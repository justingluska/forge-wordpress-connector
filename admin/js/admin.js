/**
 * Forge Connector Admin JavaScript
 */

(function($) {
    'use strict';

    var ForgeAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            $('#forge-connect-form').on('submit', this.handleConnect.bind(this));
            $('#forge-test-connection').on('click', this.handleTest.bind(this));
            $('#forge-disconnect').on('click', this.handleDisconnect.bind(this));
            $('#forge-copy-url').on('click', this.handleCopyUrl.bind(this));
        },

        /**
         * Handle copy URL button
         */
        handleCopyUrl: function(e) {
            var self = this;
            var $btn = $(e.currentTarget);
            var $copyText = $btn.find('.forge-copy-text');
            var $copiedText = $btn.find('.forge-copied-text');
            var url = $('#forge-site-url').val();

            // Show copied state helper
            function showCopiedState() {
                $copyText.hide();
                $copiedText.show();
                $btn.addClass('copied');

                setTimeout(function() {
                    $copyText.show();
                    $copiedText.hide();
                    $btn.removeClass('copied');
                }, 2000);
            }

            // Fallback copy method using temporary textarea
            function fallbackCopy() {
                var textarea = document.createElement('textarea');
                textarea.value = url;
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();

                try {
                    document.execCommand('copy');
                    showCopiedState();
                } catch (err) {
                    console.error('Fallback copy failed:', err);
                }

                document.body.removeChild(textarea);
            }

            // Try modern clipboard API first, fall back to execCommand
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    showCopiedState();
                }).catch(function(err) {
                    console.warn('Clipboard API failed, using fallback:', err);
                    fallbackCopy();
                });
            } else {
                fallbackCopy();
            }
        },

        /**
         * Handle connect form submission
         */
        handleConnect: function(e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var $btn = $form.find('.forge-connect-btn');
            var $btnText = $btn.find('.forge-btn-text');
            var $btnLoading = $btn.find('.forge-btn-loading');
            var key = $('#forge-connection-key').val().trim();

            if (!key) {
                this.showMessage('Please enter your connection key.', 'error');
                return;
            }

            if (!key.startsWith('fk_')) {
                this.showMessage('Invalid key format. Connection keys start with "fk_".', 'error');
                return;
            }

            // Show loading state
            $btn.prop('disabled', true);
            $btnText.hide();
            $btnLoading.show();
            this.hideMessage();

            $.ajax({
                url: forgeConnector.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'forge_save_key',
                    nonce: forgeConnector.nonce,
                    connection_key: key
                },
                success: function(response) {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        // Reload page to show connected state
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        this.showMessage(response.data.message || 'Connection failed.', 'error');
                        $btn.prop('disabled', false);
                        $btnText.show();
                        $btnLoading.hide();
                    }
                }.bind(this),
                error: function() {
                    this.showMessage('Connection failed. Please try again.', 'error');
                    $btn.prop('disabled', false);
                    $btnText.show();
                    $btnLoading.hide();
                }.bind(this)
            });
        },

        /**
         * Handle test connection
         */
        handleTest: function(e) {
            var $btn = $(e.currentTarget);
            var $btnText = $btn.find('.forge-btn-text');
            var $btnLoading = $btn.find('.forge-btn-loading');

            $btn.prop('disabled', true);
            $btnText.hide();
            $btnLoading.show();
            this.hideMessage();

            $.ajax({
                url: forgeConnector.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'forge_test_connection',
                    nonce: forgeConnector.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                    } else {
                        this.showMessage(response.data.message || 'Test failed.', 'error');
                    }
                }.bind(this),
                error: function() {
                    this.showMessage('Test failed. Please try again.', 'error');
                }.bind(this),
                complete: function() {
                    $btn.prop('disabled', false);
                    $btnText.show();
                    $btnLoading.hide();
                }
            });
        },

        /**
         * Handle disconnect
         */
        handleDisconnect: function(e) {
            if (!confirm('Are you sure you want to disconnect from Forge? You will need to reconnect to publish content.')) {
                return;
            }

            var $btn = $(e.currentTarget);
            $btn.prop('disabled', true);
            this.hideMessage();

            $.ajax({
                url: forgeConnector.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'forge_disconnect',
                    nonce: forgeConnector.nonce
                },
                success: function(response) {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        this.showMessage(response.data.message || 'Disconnect failed.', 'error');
                        $btn.prop('disabled', false);
                    }
                }.bind(this),
                error: function() {
                    this.showMessage('Disconnect failed. Please try again.', 'error');
                    $btn.prop('disabled', false);
                }.bind(this)
            });
        },

        /**
         * Show message
         */
        showMessage: function(message, type) {
            var $msg = $('#forge-message');
            $msg.removeClass('success error')
                .addClass(type)
                .text(message)
                .show();
        },

        /**
         * Hide message
         */
        hideMessage: function() {
            $('#forge-message').hide();
        }
    };

    // Initialize when ready
    $(document).ready(function() {
        ForgeAdmin.init();
    });

})(jQuery);
