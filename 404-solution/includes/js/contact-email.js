/**
 * Resolve protected contact placeholders into mailto links after the page
 * reaches the browser. The degraded page keeps a forum link usable when this
 * asset is missing or JavaScript is disabled.
 */
(function (window, document) {
    'use strict';

    var SELECTOR = '[data-contact-email]';

    function decodeBase64(value, label) {
        if (!value) {
            window.console.error('404 Solution contact email decode failed: missing ' + label);
            return null;
        }
        try {
            return window.atob(value);
        } catch (error) {
            window.console.error('404 Solution contact email decode failed for ' + label, error);
            return null;
        }
    }

    function addressFor(element) {
        var user = decodeBase64(element.getAttribute('data-contact-user'), 'user');
        var domain = decodeBase64(element.getAttribute('data-contact-domain'), 'domain');
        if (!user || !domain || user.indexOf('@') !== -1 || domain.indexOf('@') !== -1) {
            window.console.error('404 Solution contact email data is invalid');
            return null;
        }
        return user + '@' + domain;
    }

    function renderContactEmails(root) {
        var candidates = [];
        if (root && root.nodeType === 1 && root.matches(SELECTOR)) {
            candidates.push(root);
        }
        if (root && typeof root.querySelectorAll === 'function') {
            candidates = candidates.concat(Array.prototype.slice.call(root.querySelectorAll(SELECTOR)));
        }

        candidates.forEach(function (element) {
            if (element.getAttribute('data-contact-email-rendered') === '1') {
                return;
            }

            var address = addressFor(element);
            if (!address) {
                return;
            }

            var subject = element.getAttribute('data-contact-subject') || '[404 Solution] ';
            var body = element.getAttribute('data-contact-body');
            var href = 'mail' + 'to:' + address;
            if (subject) {
                href += '?subject=' + window.encodeURIComponent(subject);
            }
            if (body) {
                href += subject ? '&body=' : '?body=';
                href += window.encodeURIComponent(body);
            }

            if (element.tagName.toLowerCase() === 'a') {
                element.setAttribute('href', href);
                if (element.getAttribute('data-contact-email-text') === 'address') {
                    element.textContent = address;
                }
            } else {
                var link = document.createElement('a');
                link.setAttribute('href', href);
                link.textContent = element.getAttribute('data-contact-email-text') === 'address'
                    ? address : (element.textContent || address);
                element.textContent = '';
                element.appendChild(link);
            }
            element.setAttribute('data-contact-email-rendered', '1');
        });
    }

    function watchContactEmailPlaceholders() {
        if (!document.body || typeof window.MutationObserver !== 'function') {
            return;
        }
        var observer = new window.MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                    if (node.nodeType === 1) {
                        renderContactEmails(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function initialize() {
        renderContactEmails(document);
        watchContactEmailPlaceholders();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}(window, document));
