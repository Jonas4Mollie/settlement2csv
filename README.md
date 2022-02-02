# Mollie Settlement to CSV Converter

## What is this?

Unfortunately, Mollie does not provide an API endpoint for downloading settlement files as CSV. Settlement information can only be downloaded through the Mollie Dashboard manually. If one wants to connect an On-Prem Accounting System (like SAP) to Mollie, this tool can be used to download the settlement data as CSV.

The advantage here is that the entire accounting process can be automated, without the need to implement the API in a custom solution into the accounting system. Most systems already provide an integrated CSV mapper, so it's easier to just work with CSV files.

⚠️ Please also note that this is purely a proof-of-concept type of solution, and in no way is ready for production use. Its purpose is to inspire merchants' tech teams to build a similar solution on their technology stack. It's not a batteries included, one size fits all, <insert business phrase here> solution! DO NOT USE IN PRODUCTION! No support can and will be given. ⚠️

## Requirements

* Some recent version of PHP (8.0 or newer should do)
* Composer to install the dependencies

## Installation and Configuration

* `git clone https://github.com/fjbender/settlement2csv`
* `cd settlement2csv`
* `composer install`

Edit `.env` include a valid Mollie Organizational Access Token with the scopes `settlements.read`, `invoices.read` and `payments.read`

## Usage

The application only exposes the `convert` command. Optionall, you can pass a settlement ID in the format `stl_foobar`:

```
$ php settlement2csv.php convert stl_foobar
Grabbing Settlement ID: stl_foobar
Writing to file: stl_foobar.csv
Expecting about 409 transactions
409/409 [============================] 100% 19 secs/19 secs
Done
```

## Limitations

* The application only approximates the actual Mollie Settlement Export from the Dashboard. There is some information that cannot be added without slowing down the application too much, especially when dealing with captures. The description of captures is thusly slightly different, but should provide sufficient information to reconcile.
* There is a small bug in the Mollie API that causes different amounts to be returned for the withheld transaction fees when the settlement spans over two months. In sum the amount is correct, but some fees will be attributed to the wrong invoice.

## License

[BSD (Berkeley Software Distribution) License](https://opensource.org/licenses/bsd-license.php).

Copyright (c) 2021, Florian Bender
