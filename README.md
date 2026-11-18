# Composable Product Kit Stock for [Dolibarr ERP & CRM](https://www.dolibarr.org)

## Features

Get product kit composable stock. A product's composable stock is a virtual stock level calculated using the available physical stock of the subproducts composing the kit.

- See composable product kit stock on product card and product stock card
- See composable product kit stock on product list
- See composable product kit stock per warehouse
- Dedicated API route to get composable product kit stock
- Export composable product kit stock to CSV
- Send the composable stock export automatically by email to the contacts of a category, using Dolibarr email templates and scheduled jobs

### Automatic email sending

Configure it in the module setup page:

1. Create a **public** email template of type "Composable kit stock export" in *Setup > Emails > Email templates*. For several languages, create one template per language with the same code (and the same position).
2. Tag the recipient contacts with a contact category.
3. In the module setup, select the product category, the exported fields, the contact category, the sender, the template and the schedule (recurrence, sending hours, working days only, number of emails per sending task), then enable automatic sending.

Requirements: the *Scheduled jobs* module enabled with the system cron calling Dolibarr's scheduled jobs, and a valid sender email configured in Dolibarr.

Each contact receives the email in their language (contact language or country, else third party language or country, else the default language). Each sent or failed email is recorded as an event on the contact.

## Module installation

Prerequisites: You must have Dolibarr ERP & CRM software installed. You can download it from [Dolibarr.org](https://www.dolibarr.org).
You can also get a ready-to-use instance in the cloud from https://saas.dolibarr.org

### Download the module

You can download the module package from the official Dolibarr marketplace [Dolistore](https://www.dolistore.com)

### From the ZIP file and GUI interface

If the module is a ready-to-deploy zip file, so with a name `module_composableproductkitstock-1.0.0.zip` (e.g., when downloading it from a marketplace like [Dolistore](https://www.dolistore.com)),
go to menu `Home> Setup> Modules> Deploy external module` and upload the zip file.

<!--

Note: If this screen tells you that there is no "custom" directory, check that your setup is correct:

- In your Dolibarr installation directory, edit the `htdocs/conf/conf.php` file and check that the following lines are not commented:

    ```php
    //$dolibarr_main_url_root_alt ...
    //$dolibarr_main_document_root_alt ...
    ```

- Uncomment them if necessary (delete the leading `//`) and assign the proper value according to your Dolibarr installation

    For example :

    - UNIX:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
        ```

    - Windows:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
        ```
-->

<!--

### From a GIT repository

Clone the repository in `$dolibarr_main_document_root_alt/composableproductkitstock`

```shell
cd ....../custom
git clone git@github.com:gitlogin/composableproductkitstock.git composableproductkitstock
```

-->

### Final steps

Using your browser:

  - Log into Dolibarr as a super-administrator
  - Go to "Setup"> "Modules"
  - You should now be able to find and enable the module

## Translations

Translations can be completed manually by editing files in the module directories under `langs`.

## License

GNU GPLv3
