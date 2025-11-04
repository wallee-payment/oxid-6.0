

# OXID 6.X

v1.0.51, 2025-11

This repository contains the OXID  wallee payment module that enables the shop to process payments with [wallee](https://www.wallee.com).

##### To use this extension, a [wallee](https://app-wallee.com/user/signup) account is required.

## Requirements

* [Oxid](https://www.oxid-esales.com/) 6.0, 6.1, 6.2, 6.10
* [PHP](http://php.net/) 5.6 or later

## Install Oxid 6.2+

 Run on the same path via terminal (required on oxid 6.2 upwards) this command to install the plugin: +
```
composer require wallee/oxid-6.0
```
If the plugin still don't work you need to run these commands:
```
./vendor/bin/oe-console oe:module:install source/modules/wle/Wallee
./vendor/bin/oe-console oe:module:install-configuration source/modules/wle/Wallee
./vendor/bin/oe-console oe:module:activate Wallee
./vendor/bin/oe-console oe:module:apply-configuration
```

## Support

Support queries can be issued on the [wallee support site](https://app-wallee.com/space/select?target=/support).

## Documentation

* [English](https://plugin-documentation.wallee.com/wallee-payment/oxid-6.0/1.0.51/docs/en/documentation.html)

## License

Please see the [license file](https://github.com/wallee-payment/oxid-6.0/blob/1.0.51/LICENSE) for more information.