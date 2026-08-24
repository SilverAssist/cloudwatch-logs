/**
 * Silver Assist CloudWatch Logs - Log viewer
 *
 * Runs searches against the CloudWatch Logs API through admin-ajax, renders the
 * results, paginates with the token the API returns, and optionally follows the
 * log by polling for events newer than the last one on screen.
 *
 * @file viewer.js
 * @version 1.0.0
 * @author Silver Assist
 * @since 1.0.0
 */

(() => {
    "use strict";

    /**
     * Messages longer than this are collapsed behind their first line.
     *
     * @constant {number}
     * @since 1.0.0
     */
    const COLLAPSE_AFTER_LINES = 12;

    /**
     * Consecutive failures before tailing gives up.
     *
     * Retrying forever would keep spending a quota shared with everything else
     * in the AWS account.
     *
     * @constant {number}
     * @since 1.0.0
     */
    const MAX_TAIL_FAILURES = 5;

    /**
     * How close to the bottom the reader must be for new rows to scroll in.
     *
     * Further up means they are reading history and must not be yanked.
     *
     * @constant {number}
     * @since 1.0.0
     */
    const AUTOSCROLL_SLACK_PX = 120;

    /**
     * The slowest refresh the tail falls back to when nothing else says.
     *
     * @constant {number}
     * @since 1.0.0
     */
    const FALLBACK_MAX_INTERVAL_S = 300;

    /**
     * One event as returned by the search endpoint.
     *
     * @typedef {Object} LogEvent
     * @property {string} id - Event id, used to deduplicate while tailing.
     * @property {number} timestamp - Event time in milliseconds.
     * @property {string} time - Event time preformatted in the site's timezone.
     * @property {string} stream - Log stream the event came from.
     * @property {string} message - Raw log message.
     * @since 1.0.0
     */

    /**
     * Viewer settings localized by AssetManager.
     *
     * @typedef {Object} ViewerConfig
     * @property {number} pollInterval - Configured seconds between tail polls.
     * @property {number} minInterval - Lowest interval the quota allows.
     * @property {number} maxInterval - Highest interval the backoff may reach.
     * @property {string} logGroup - Log group being read, used to name exports.
     * @since 1.0.0
     */

    /**
     * Drives one log viewer screen.
     *
     * @since 1.0.0
     */
    class LogViewer {

        /**
         * Build a viewer bound to the elements of a rendered screen.
         *
         * @since 1.0.0
         * @param {HTMLElement} root - The viewer container.
         */
        constructor(root) {
            /** @type {HTMLElement} */
            this.root = root;

            /** @type {Object<string, string>} */
            this.i18n = (window.silverAssistCloudWatch || {}).i18n || {};

            /** @type {string} */
            this.ajaxUrl = (window.silverAssistCloudWatch || {}).ajaxUrl || "";

            /** @type {string} */
            this.nonce = (window.silverAssistCloudWatch || {}).nonce || "";

            /** @type {ViewerConfig} */
            this.settings = window.silverAssistCloudWatchViewer || {};

            this.el = {
                range: document.getElementById("sacw-range"),
                customRange: root.querySelector(".sacw-custom-range"),
                start: document.getElementById("sacw-start"),
                end: document.getElementById("sacw-end"),
                search: document.getElementById("sacw-search"),
                searchMode: document.getElementById("sacw-search-mode"),
                streamPrefix: document.getElementById("sacw-stream-prefix"),
                run: document.getElementById("sacw-search-run"),
                spinner: root.querySelector(".sacw-viewer-spinner"),
                body: document.getElementById("sacw-events-body"),
                error: document.getElementById("sacw-viewer-error"),
                empty: document.getElementById("sacw-viewer-empty"),
                count: root.querySelector(".sacw-result-count"),
                loadMore: document.getElementById("sacw-load-more"),
                tail: document.getElementById("sacw-tail"),
                exportCsv: document.getElementById("sacw-export-csv"),
                exportJson: document.getElementById("sacw-export-json")
            };

            /** @type {string} */
            this.nextToken = "";

            /** @type {number} */
            this.lastTimestamp = 0;

            /** @type {Set<string>} */
            this.seen = new Set();

            /** @type {LogEvent[]} */
            this.events = [];

            /** @type {boolean} */
            this.running = false;

            /** @type {boolean} */
            this.tailing = false;

            /** @type {?number} */
            this.tailTimer = null;

            /** @type {number} */
            this.tailInterval = this.baseInterval();

            /** @type {number} */
            this.tailFailures = 0;
        }

        /**
         * The configured refresh interval, never below the quota floor.
         *
         * @since 1.0.0
         * @returns {number} Seconds between tail polls.
         */
        baseInterval() {
            const { minInterval = 5, pollInterval = 10 } = this.settings;

            return Math.max(minInterval, pollInterval);
        }

        /**
         * The slowest the tail is allowed to get before it stops being useful.
         *
         * @since 1.0.0
         * @returns {number} Seconds.
         */
        maxInterval() {
            return this.settings.maxInterval || FALLBACK_MAX_INTERVAL_S;
        }

        /**
         * Whether the reader is close enough to the bottom to follow new rows.
         *
         * @since 1.0.0
         * @returns {boolean} True when new rows should scroll into view.
         */
        isNearBottom() {
            const fromBottom = document.documentElement.scrollHeight - (window.scrollY + window.innerHeight);

            return fromBottom <= AUTOSCROLL_SLACK_PX;
        }

        /**
         * Convert a datetime-local value into epoch milliseconds.
         *
         * @since 1.0.0
         * @param {string} value - The field value.
         * @returns {number} Milliseconds, or 0 when the field is empty or invalid.
         */
        toMilliseconds(value) {
            if (!value) {
                return 0;
            }

            const parsed = Date.parse(value);

            return Number.isNaN(parsed) ? 0 : parsed;
        }

        /**
         * Collect the current search parameters.
         *
         * @since 1.0.0
         * @param {string} nextToken - Pagination token, empty for a first page.
         * @param {number} sinceMs - Fetch only events after this time, 0 to ignore.
         * @returns {FormData} The request payload.
         */
        buildPayload(nextToken, sinceMs) {
            const payload = new FormData();

            payload.append("action", "silver_assist_cloudwatch_search_logs");
            payload.append("nonce", this.nonce);
            payload.append("range", this.el.range.value);
            payload.append("search", this.el.search.value);
            payload.append("search_mode", this.el.searchMode.value);
            payload.append("stream_prefix", this.el.streamPrefix.value);

            if (nextToken) {
                payload.append("next_token", nextToken);
            }

            if (sinceMs) {
                payload.append("since_ms", String(sinceMs));
            }

            if (this.el.range.value === "custom") {
                payload.append("start_ms", String(this.toMilliseconds(this.el.start.value)));
                payload.append("end_ms", String(this.toMilliseconds(this.el.end.value)));
            }

            return payload;
        }

        /**
         * Pretty-print a message that is entirely JSON, leaving anything else alone.
         *
         * @since 1.0.0
         * @param {string} message - The raw log message.
         * @returns {string} The message, indented when it parses as JSON.
         */
        formatMessage(message) {
            const trimmed = (message || "").trim();

            if (!trimmed.startsWith("{") && !trimmed.startsWith("[")) {
                return message;
            }

            try {
                return JSON.stringify(JSON.parse(trimmed), null, 2);
            } catch (error) {
                return message;
            }
        }

        /**
         * Write a message into an element, marking the parts that matched.
         *
         * Only a literal text search is highlighted: a CloudWatch filter pattern
         * and its regex dialect are not JavaScript regular expressions, so
         * reimplementing them here would mark the wrong spans.
         *
         * Every part is written with textContent, never innerHTML, because log
         * lines routinely contain markup that must not be parsed.
         *
         * @since 1.0.0
         * @param {HTMLElement} target - The element to fill.
         * @param {string} text - The formatted message.
         * @returns {void}
         */
        highlight(target, text) {
            const term = this.el.searchMode.value === "text" ? this.el.search.value.trim() : "";
            const needle = term.toLowerCase();

            if (!needle) {
                target.textContent = text;
                return;
            }

            const haystack = text.toLowerCase();
            let cursor = 0;
            let found = haystack.indexOf(needle);

            if (found === -1) {
                target.textContent = text;
                return;
            }

            while (found !== -1) {
                target.appendChild(document.createTextNode(text.slice(cursor, found)));

                const mark = document.createElement("mark");
                mark.textContent = text.slice(found, found + needle.length);
                target.appendChild(mark);

                cursor = found + needle.length;
                found = haystack.indexOf(needle, cursor);
            }

            target.appendChild(document.createTextNode(text.slice(cursor)));
        }

        /**
         * Render a message, collapsing it when it is too long to scan at a glance.
         *
         * @since 1.0.0
         * @param {string} text - The formatted message.
         * @returns {HTMLElement} The element to place in the row.
         */
        buildMessage(text) {
            const pre = document.createElement("pre");
            pre.className = "sacw-message";
            this.highlight(pre, text);

            const lines = text.split("\n");

            if (lines.length <= COLLAPSE_AFTER_LINES) {
                return pre;
            }

            const details = document.createElement("details");
            const summary = document.createElement("summary");
            summary.className = "sacw-message-summary";
            summary.textContent = lines[0];
            details.appendChild(summary);
            details.appendChild(pre);

            return details;
        }

        /**
         * Build one table row for an event.
         *
         * @since 1.0.0
         * @param {LogEvent} event - The event returned by the endpoint.
         * @returns {HTMLTableRowElement} The row.
         */
        buildRow(event) {
            const row = document.createElement("tr");

            const time = document.createElement("td");
            time.className = "sacw-col-time";
            time.textContent = event.time;
            row.appendChild(time);

            const stream = document.createElement("td");
            stream.className = "sacw-col-stream";
            stream.textContent = event.stream;
            row.appendChild(stream);

            const message = document.createElement("td");
            message.appendChild(this.buildMessage(this.formatMessage(event.message)));
            row.appendChild(message);

            return row;
        }

        /**
         * Append a page of events, skipping ones already on screen.
         *
         * @since 1.0.0
         * @param {LogEvent[]} events - The events returned by the endpoint.
         * @returns {number} How many rows were actually added.
         */
        appendEvents(events) {
            let added = 0;

            events.forEach(event => {
                if (event.id && this.seen.has(event.id)) {
                    return;
                }

                if (event.id) {
                    this.seen.add(event.id);
                }

                if (event.timestamp > this.lastTimestamp) {
                    this.lastTimestamp = event.timestamp;
                }

                this.events.push(event);
                this.el.body.appendChild(this.buildRow(event));
                added += 1;
            });

            return added;
        }

        /**
         * Forget every loaded event, so the next search starts clean.
         *
         * @since 1.0.0
         * @returns {void}
         */
        reset() {
            this.el.body.textContent = "";
            this.seen = new Set();
            this.events = [];
            this.lastTimestamp = 0;
            this.nextToken = "";
        }

        /**
         * Show an error above the results.
         *
         * @since 1.0.0
         * @param {string} message - The message to show, empty to hide the notice.
         * @returns {void}
         */
        showError(message) {
            this.el.error.textContent = message || "";
            this.el.error.hidden = !message;
        }

        /**
         * Report how many events are currently loaded.
         *
         * @since 1.0.0
         * @returns {void}
         */
        updateCount() {
            const loaded = this.el.body.childElementCount;
            let text = loaded ? (this.i18n.eventsLoaded || "").replace("%d", String(loaded)) : "";

            if (this.tailing) {
                text = text ? `${text} · ${this.i18n.tailing}` : this.i18n.tailing;
            }

            this.el.count.textContent = text;
            this.el.empty.hidden = loaded > 0 || this.running;
        }

        /**
         * Enable the export buttons only when there is something to export.
         *
         * @since 1.0.0
         * @returns {void}
         */
        updateExportButtons() {
            const empty = this.events.length === 0;

            this.el.exportCsv.disabled = empty;
            this.el.exportJson.disabled = empty;
        }

        /**
         * Run a search.
         *
         * Tail mode is passed explicitly rather than inferred from the
         * timestamp: the first poll after enabling the tail has no events to
         * follow yet, and inferring would send it down the pagination branch.
         *
         * @since 1.0.0
         * @param {boolean} append - Whether to keep the rows already on screen.
         * @param {boolean} tailMode - Whether this is a tail poll rather than a search.
         * @returns {Promise<void>} Resolves once the page has been rendered.
         */
        async search(append, tailMode) {
            if (this.running) {
                return;
            }

            const sinceMs = tailMode ? this.lastTimestamp : 0;
            const follow = tailMode && this.isNearBottom();

            this.running = true;
            this.el.spinner.classList.add("is-active");
            this.el.run.disabled = true;

            if (!tailMode) {
                this.showError("");
            }

            if (!append) {
                this.reset();
            }

            try {
                const response = await window.fetch(this.ajaxUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    body: this.buildPayload(append && !tailMode ? this.nextToken : "", sinceMs)
                });
                const body = await response.json();
                const data = (body && body.data) || {};

                if (!body || !body.success) {
                    this.handleFailure(data, tailMode);
                } else {
                    this.renderPage(data, tailMode, follow);
                }
            } catch (error) {
                this.handleFailure({ message: this.i18n.requestError }, tailMode);
            } finally {
                this.running = false;
                this.el.spinner.classList.remove("is-active");
                this.el.run.disabled = false;
                this.updateCount();
                this.updateExportButtons();

                if (this.tailing) {
                    this.scheduleTail();
                }
            }
        }

        /**
         * Render a successful page of results.
         *
         * @since 1.0.0
         * @param {Object} data - The response payload.
         * @param {LogEvent[]} [data.events] - The events returned.
         * @param {string} [data.nextToken] - Token for the following page.
         * @param {boolean} tailMode - Whether this came from a tail poll.
         * @param {boolean} follow - Whether new rows should scroll into view.
         * @returns {void}
         */
        renderPage(data, tailMode, follow) {
            const added = this.appendEvents(data.events || []);

            if (!tailMode) {
                this.nextToken = data.nextToken || "";
                this.el.loadMore.hidden = !this.nextToken;
                return;
            }

            this.tailFailures = 0;
            this.tailInterval = this.baseInterval();

            if (added && follow) {
                window.scrollTo(0, document.documentElement.scrollHeight);
            }
        }

        /**
         * React to a failed request, slowing tailing down rather than repeating it.
         *
         * @since 1.0.0
         * @param {Object} data - The error payload.
         * @param {string} [data.message] - Human readable failure description.
         * @param {boolean} [data.throttled] - Whether AWS refused the request rate.
         * @param {boolean} tailMode - Whether the failure came from a tail poll.
         * @returns {void}
         */
        handleFailure(data, tailMode) {
            if (!tailMode) {
                this.showError(data.message || this.i18n.requestError);
                return;
            }

            this.tailFailures += 1;

            if (data.throttled) {
                // Back off exponentially: the quota is shared with the rest of
                // the AWS account, so retrying at the same cadence only
                // prolongs the throttling.
                this.tailInterval = Math.min(this.tailInterval * 2, this.maxInterval());
                this.showError(this.i18n.tailSlowed);
                return;
            }

            this.showError(data.message || this.i18n.requestError);

            if (this.tailFailures >= MAX_TAIL_FAILURES) {
                this.stopTail();
                this.showError(this.i18n.tailStopped);
            }
        }

        /**
         * Queue the next tail poll.
         *
         * @since 1.0.0
         * @returns {void}
         */
        scheduleTail() {
            window.clearTimeout(this.tailTimer);

            this.tailTimer = window.setTimeout(() => {
                if (this.tailing) {
                    this.search(true, true);
                }
            }, this.tailInterval * 1000);
        }

        /**
         * Start following the log.
         *
         * @since 1.0.0
         * @returns {void}
         */
        startTail() {
            this.tailing = true;
            this.tailFailures = 0;
            this.tailInterval = this.baseInterval();
            this.el.count.classList.add("sacw-tailing");

            if (!this.running) {
                this.scheduleTail();
            }
        }

        /**
         * Stop following the log.
         *
         * @since 1.0.0
         * @returns {void}
         */
        stopTail() {
            this.tailing = false;
            window.clearTimeout(this.tailTimer);
            this.el.tail.checked = false;
            this.el.count.classList.remove("sacw-tailing");
        }

        /**
         * Hand the loaded events to the browser as a file.
         *
         * @since 1.0.0
         * @param {string} contents - The file body.
         * @param {string} type - The MIME type.
         * @param {string} suffix - The file extension.
         * @returns {void}
         */
        download(contents, type, suffix) {
            const blob = new Blob([contents], { type });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            const group = (this.settings.logGroup || "cloudwatch")
                .replace(/[^a-z0-9]+/gi, "-")
                .replace(/^-|-$/g, "");

            link.href = url;
            link.download = `${group}-${Date.now()}.${suffix}`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        /**
         * Quote one CSV field.
         *
         * @since 1.0.0
         * @param {*} value - The raw value.
         * @returns {string} The quoted field.
         */
        csvField(value) {
            const text = value === undefined || value === null ? "" : String(value);

            return `"${text.replace(/"/g, "\"\"")}"`;
        }

        /**
         * Export the loaded events as CSV.
         *
         * @since 1.0.0
         * @returns {void}
         */
        exportCsv() {
            const header = ["timestamp", "time", "stream", "message"];
            const rows = [header.map(field => this.csvField(field)).join(",")];

            this.events.forEach(({ timestamp, time, stream, message }) => {
                rows.push([timestamp, time, stream, message].map(value => this.csvField(value)).join(","));
            });

            this.download(rows.join("\r\n"), "text/csv;charset=utf-8", "csv");
        }

        /**
         * Export the loaded events as JSON.
         *
         * @since 1.0.0
         * @returns {void}
         */
        exportJson() {
            this.download(JSON.stringify(this.events, null, 2), "application/json", "json");
        }

        /**
         * Show the custom range fields only when that range is selected.
         *
         * @since 1.0.0
         * @returns {void}
         */
        syncRangeFields() {
            this.el.customRange.hidden = this.el.range.value !== "custom";
        }

        /**
         * Wire every control and load the default range.
         *
         * @since 1.0.0
         * @returns {void}
         */
        init() {
            this.syncRangeFields();
            this.el.range.addEventListener("change", () => this.syncRangeFields());

            this.el.run.addEventListener("click", () => this.search(false, false));
            this.el.loadMore.addEventListener("click", () => this.search(true, false));

            this.el.search.addEventListener("keydown", event => {
                if (event.key === "Enter") {
                    event.preventDefault();
                    this.search(false, false);
                }
            });

            this.el.tail.addEventListener("change", () => {
                if (this.el.tail.checked) {
                    this.startTail();
                } else {
                    this.stopTail();
                }
            });

            this.el.exportCsv.addEventListener("click", () => this.exportCsv());
            this.el.exportJson.addEventListener("click", () => this.exportJson());

            // A background tab cannot usefully follow a log and would keep
            // spending the shared API quota, so pause until it is looked at.
            document.addEventListener("visibilitychange", () => {
                if (!this.tailing) {
                    return;
                }

                if (document.hidden) {
                    window.clearTimeout(this.tailTimer);
                } else {
                    this.scheduleTail();
                }
            });

            // A configured viewer opens with the default range already loaded,
            // so the screen is useful without anyone pressing a button first.
            if (this.root.getAttribute("data-log-group")) {
                this.search(false, false);
            }
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        const root = document.querySelector(".sacw-viewer");

        if (root) {
            new LogViewer(root).init();
        }
    });
})();
