/**
 * Silver Assist CloudWatch Logs - Settings screen behaviour
 *
 * Shows only the credential fields belonging to the selected authentication
 * mode, suggests log groups from the AWS account, and validates the connection
 * without leaving the page.
 *
 * @file admin.js
 * @version 1.0.0
 * @author Silver Assist
 * @since 1.0.0
 */

(() => {
    "use strict";

    /**
     * Milliseconds of silence before the log group suggestions are refreshed.
     *
     * One request per pause in typing, never one per keystroke: CloudWatch
     * allows only a handful of calls per second across the whole AWS account.
     *
     * @constant {number}
     * @since 1.0.0
     */
    const SUGGEST_DEBOUNCE_MS = 400;

    /**
     * Settings localized by AssetManager.
     *
     * @typedef {Object} AdminConfig
     * @property {string} ajaxUrl - URL of admin-ajax.php.
     * @property {string} nonce - Nonce for this plugin's AJAX actions.
     * @property {Object<string, string>} i18n - Translated interface strings.
     * @since 1.0.0
     */

    /** @type {AdminConfig} */
    const config = window.silverAssistCloudWatch || {};

    /**
     * Credential fields that can be sent with a connection test.
     *
     * @constant {string[]}
     * @since 1.0.0
     */
    const CREDENTIAL_FIELDS = ["region", "log_group", "access_key_id", "secret_access_key", "secret_id"];

    /**
     * Read the authentication mode currently selected in the form.
     *
     * @since 1.0.0
     * @returns {string} The selected mode, defaulting to "auto".
     */
    const selectedAuthMode = () => {
        const checked = document.querySelector("input[name=\"auth_mode\"]:checked");

        return checked ? checked.value : "auto";
    };

    /**
     * Show only the field group belonging to the selected authentication mode.
     *
     * @since 1.0.0
     * @returns {void}
     */
    const syncAuthFields = () => {
        const mode = selectedAuthMode();

        document.querySelectorAll(".sacw-auth-fields").forEach(group => {
            group.hidden = group.getAttribute("data-auth-mode") !== mode;
        });
    };

    /**
     * Collect the values a request should be run against.
     *
     * Disabled fields are skipped: they are locked by a wp-config.php constant,
     * and the server already reads those itself.
     *
     * @since 1.0.0
     * @param {string} action - The admin-ajax action to call.
     * @returns {FormData} The request payload.
     */
    const buildPayload = action => {
        const payload = new FormData();

        payload.append("action", action);
        payload.append("nonce", config.nonce || "");
        payload.append("auth_mode", selectedAuthMode());

        CREDENTIAL_FIELDS.forEach(field => {
            const input = document.querySelector(`[name="${field}"]`);

            if (input && !input.disabled) {
                payload.append(field, input.value);
            }
        });

        return payload;
    };

    /**
     * Call an admin-ajax action of this plugin.
     *
     * @since 1.0.0
     * @param {string} action - The admin-ajax action to call.
     * @param {Object<string, string>} [extra] - Values overriding the form ones.
     * @returns {Promise<Object>} The decoded response body.
     */
    const request = async (action, extra = {}) => {
        const payload = buildPayload(action);

        Object.entries(extra).forEach(([key, value]) => payload.set(key, value));

        const response = await window.fetch(config.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: payload
        });

        return response.json();
    };

    /**
     * Call a function once the caller stops asking for it.
     *
     * @since 1.0.0
     * @param {Function} callback - Function to defer.
     * @param {number} wait - Milliseconds of silence to wait for.
     * @returns {Function} The debounced function.
     */
    const debounce = (callback, wait) => {
        let timer = null;

        return () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(callback, wait);
        };
    };

    /**
     * Fill the log group datalist with the groups the credentials can see.
     *
     * Suggestions are a convenience, so a failure is swallowed: the name can
     * always be typed by hand.
     *
     * @since 1.0.0
     * @param {HTMLInputElement} input - The log group field.
     * @param {HTMLElement} datalist - The datalist backing it.
     * @returns {Promise<void>} Resolves once the suggestions are in place.
     */
    const loadLogGroups = async (input, datalist) => {
        let groups = null;

        try {
            const body = await request("silver_assist_cloudwatch_list_log_groups", { log_group: input.value });

            groups = body && body.success && body.data ? body.data.log_groups : null;
        } catch (error) {
            return;
        }

        if (!groups) {
            return;
        }

        datalist.textContent = "";

        groups.forEach(name => {
            const option = document.createElement("option");
            option.value = name;
            datalist.appendChild(option);
        });
    };

    /**
     * Replace the connection status card with freshly rendered markup.
     *
     * @since 1.0.0
     * @param {string} html - Rendered card markup, built server-side.
     * @returns {void}
     */
    const replaceStatusCard = html => {
        const current = document.querySelector(".sacw-status-card");

        if (!current || !html) {
            return;
        }

        const holder = document.createElement("div");
        holder.innerHTML = html;

        const replacement = holder.firstElementChild;

        if (replacement) {
            current.replaceWith(replacement);
        }
    };

    /**
     * Run the connection test and report the outcome.
     *
     * @since 1.0.0
     * @param {HTMLButtonElement} button - The button that triggered the test.
     * @param {HTMLElement} spinner - The spinner shown while waiting.
     * @param {HTMLElement} result - The element receiving the outcome message.
     * @returns {Promise<void>} Resolves once the outcome has been rendered.
     */
    const testConnection = async (button, spinner, result) => {
        const { i18n = {} } = config;

        button.disabled = true;
        spinner.classList.add("is-active");
        result.textContent = i18n.testing || "";
        result.className = "sacw-test-result";

        try {
            const body = await request("silver_assist_cloudwatch_test_connection");
            const data = body && body.data ? body.data : {};
            const succeeded = Boolean(body && body.success);

            result.textContent = data.message || "";
            result.className = `sacw-test-result notice inline ${succeeded ? "notice-success" : "notice-error"}`;

            replaceStatusCard(data.html);
        } catch (error) {
            result.textContent = i18n.requestError || "";
            result.className = "sacw-test-result notice inline notice-error";
        } finally {
            button.disabled = false;
            spinner.classList.remove("is-active");
        }
    };

    /**
     * Wire the log group field to its suggestions.
     *
     * @since 1.0.0
     * @returns {void}
     */
    const initLogGroupSuggestions = () => {
        const input = document.getElementById("sacw-log-group");
        const datalist = document.getElementById("sacw-log-group-options");

        if (!input || !datalist || input.disabled) {
            return;
        }

        const refresh = debounce(() => loadLogGroups(input, datalist), SUGGEST_DEBOUNCE_MS);

        input.addEventListener("focus", refresh, { once: true });
        input.addEventListener("input", refresh);
    };

    /**
     * Wire the connection test button.
     *
     * @since 1.0.0
     * @returns {void}
     */
    const initConnectionTest = () => {
        const button = document.getElementById("sacw-test-connection");
        const spinner = document.querySelector(".sacw-spinner");
        const result = document.getElementById("sacw-test-result");

        if (!button || !spinner || !result) {
            return;
        }

        button.addEventListener("click", () => testConnection(button, spinner, result));
    };

    document.addEventListener("DOMContentLoaded", () => {
        syncAuthFields();

        document.querySelectorAll("input[name=\"auth_mode\"]").forEach(radio => {
            radio.addEventListener("change", syncAuthFields);
        });

        initLogGroupSuggestions();
        initConnectionTest();
    });
})();
