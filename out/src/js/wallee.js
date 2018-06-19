(function ($) {
    window.Wallee = {
        handler: null,
        methodConfigurationId: null,
        running: false,
        loaded: false,

        initialized: function () {
            $('#Wallee-iframe-spinner').hide();
            $('#Wallee-iframe-container').show();
            $('#button-confirm').removeAttr('disabled');
            $('#button-confirm').click(function (event) {
            	event.preventDefault();
                Wallee.handler.validate();
                $('#button-confirm').attr('disabled', 'disabled');
                return false;
            });
            this.loaded = true;
        },
        
        heightChanged: function () {
        	if(this.loaded && $('#Wallee-iframe-container > iframe').height() == 0) {
        		$('#Wallee-iframe-container').parent().parent().hide();
        	}
        },

        submit: function () {
            if (Wallee.running) {
                return;
            }
            Wallee.running = true;
            var params = '&stoken=' + $('input[name=stoken]').val();
            params += '&sDeliveryAddressMD5=' + $('input[name=sDeliveryAddressMD5]').val();
            params += '&challenge=' + $('input[name=challenge]').val();
            $.getJSON('index.php?cl=order&fnc=wleConfirm' + params, '', function (data, status, jqXHR) {
                if (data.status) {
                    Wallee.handler.submit();
                } else {
                    Wallee.addError(data.message);
                    $('#button-confirm').removeAttr('disabled');
                }
                Wallee.running = false;
            }).fail((function(jqXHR, textStatus, errorThrown) {
                alert("Something went wrong: " + errorThrown);
            }));
        },

        validated: function (result) {
            if (result.success) {
                Wallee.submit();
            } else {
                if (result.errors) {
                    for (var i = 0; i < result.errors.length; i++) {
                        Wallee.addError(result.errors[i]);
                    }
                }
                $('#button-confirm').removeAttr('disabled');
            }
        },

        init: function (methodConfigurationId) {
            if (typeof window.IframeCheckoutHandler === 'undefined') {
                setTimeout(function () {
                    Wallee.init(methodConfigurationId);
                }, 500);
            } else {
                Wallee.methodConfigurationId = methodConfigurationId;
                Wallee.handler = window
                    .IframeCheckoutHandler(methodConfigurationId);
                Wallee.handler.setInitializeCallback(this.initialized);
                Wallee.handler.setValidationCallback(this.validated);
                Wallee.handler.setHeightChangeCallback(this.heightChanged);
                Wallee.handler.create('Wallee-iframe-container');
            }
        },

        addError: function (message) {
            $('#content').find('p.alert-danger').remove();
            $('#content').prepend($("<p class='alert alert-danger'>" + message + "</p>"));
            $('html, body').animate({
                scrollTop: $('#content').find('p.alert-danger').offset().top
            }, 200);
        }
    }
})(jQuery);