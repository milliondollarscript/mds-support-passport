(function () {
    'use strict';

    var root = window.MillionDollarScript = window.MillionDollarScript || {};
    root.extensions = root.extensions || {};
    var extension = root.extensions.supportPassport = root.extensions.supportPassport || {};

    function labels() {
        return extension.admin || {};
    }

    function closeRevokePanels(except) {
        document.querySelectorAll('.mds-support-passport-revoke[open]').forEach(function (panel) {
            if (panel !== except) {
                panel.removeAttribute('open');
            }
        });
    }

    function copyText(value) {
        if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
            return window.navigator.clipboard.writeText(value);
        }

        return new Promise(function (resolve, reject) {
            var field = document.createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', 'readonly');
            field.style.position = 'fixed';
            field.style.left = '-9999px';
            document.body.appendChild(field);
            field.select();
            try {
                document.execCommand('copy');
                resolve();
            } catch (error) {
                reject(error);
            } finally {
                document.body.removeChild(field);
            }
        });
    }

    function updateMediaPreview(container, attachment) {
        var preview = container.querySelector('[data-mds-support-passport-media-preview]');
        var input = container.querySelector('[data-mds-support-passport-media-id]');
        if (!preview || !input) {
            return;
        }

        input.value = attachment && attachment.id ? attachment.id : '';
        preview.innerHTML = '';

        if (!attachment || !attachment.url) {
            var fallback = document.createElement('span');
            fallback.className = 'mds-support-passport-badge-visual is-blue is-preview';
            fallback.setAttribute('aria-hidden', 'true');
            fallback.textContent = 'S';
            preview.appendChild(fallback);
            return;
        }

        var wrapper = document.createElement('span');
        var image = document.createElement('img');
        wrapper.className = 'mds-support-passport-badge-visual is-preview';
        image.className = 'mds-support-passport-badge-image';
        image.alt = '';
        image.src = attachment.url;
        wrapper.appendChild(image);
        preview.appendChild(wrapper);
    }

    document.addEventListener('toggle', function (event) {
        var panel = event.target;
        if (!panel || !panel.matches || !panel.matches('.mds-support-passport-revoke') || !panel.open) {
            return;
        }

        closeRevokePanels(panel);

        var reason = panel.querySelector('input[name="reason"]');
        if (reason) {
            window.setTimeout(function () {
                reason.focus();
            }, 0);
        }
    }, true);

    document.addEventListener('click', function (event) {
        var copyButton = event.target.closest ? event.target.closest('.mds-support-passport-copy') : null;
        if (copyButton) {
            event.preventDefault();
            copyText(copyButton.getAttribute('data-copy') || '').then(function () {
                var original = copyButton.getAttribute('data-original-label') || copyButton.textContent;
                copyButton.setAttribute('data-original-label', original);
                copyButton.textContent = labels().copiedLabel || 'Copied';
                window.setTimeout(function () {
                    copyButton.textContent = original;
                }, 1800);
            });
            return;
        }

        var selectButton = event.target.closest ? event.target.closest('[data-mds-support-passport-select-media]') : null;
        if (selectButton) {
            event.preventDefault();
            if (!window.wp || !window.wp.media) {
                return;
            }

            var container = selectButton.closest('[data-mds-support-passport-media]');
            if (!container) {
                return;
            }

            var frame = window.wp.media({
                title: labels().mediaTitle || 'Choose badge image',
                button: {
                    text: labels().mediaButton || 'Use this image'
                },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var sizes = attachment.sizes || {};
                updateMediaPreview(container, {
                    id: attachment.id,
                    url: sizes.thumbnail && sizes.thumbnail.url ? sizes.thumbnail.url : attachment.url
                });
            });

            frame.open();
            return;
        }

        var clearButton = event.target.closest ? event.target.closest('[data-mds-support-passport-clear-media]') : null;
        if (clearButton) {
            event.preventDefault();
            var mediaContainer = clearButton.closest('[data-mds-support-passport-media]');
            if (mediaContainer) {
                updateMediaPreview(mediaContainer, null);
            }
            return;
        }

        document.querySelectorAll('.mds-support-passport-revoke[open]').forEach(function (panel) {
            if (!panel.contains(event.target)) {
                panel.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if ('Escape' === event.key) {
            closeRevokePanels();
        }
    });
}());
