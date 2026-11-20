# Composable Product Kit Stock for [Dolibarr ERP & CRM](https://www.dolibarr.org)

## Features

Get product kit composable stock. A product's composable stock is a virtual stock level calculated using the available physical stock of the subproducts composing the kit.

- See composable product kit stock on product card and product stock card
- See composable product kit stock on product list
- See composable product kit stock per warehouse
- Dedicated API route to get composable product kit stock
- Export composable product kit stock to CSV
- Send the composable stock export automatically by email to the contacts of a category, using Dolibarr email templates and scheduled jobs

### How composable stock is calculated

For each subproduct of the kit, the module divides its physical stock by the quantity needed in the kit and rounds down. The kit's composable stock is the smallest of these values.

- **Warehouses**: only open warehouses count (open and internal ones when the warehouse extra status is enabled). Stock in closed warehouses is ignored. The total combines all these warehouses, as if the subproducts could be transferred to one place; the per-warehouse detail shows what can be composed in each warehouse on its own.
- **Every subproduct counts**, including those where "Increase/Decrease stock on parent change" is unticked on the kit's composition tab: they are still physically needed to compose a kit.
- **Services** in the kit are ignored. A subproduct with a quantity of 0 in the kit is ignored.
- **Negative stock**: a subproduct with a negative stock composes no kit (0).
- **Nested kits are not expanded**: a subproduct that is itself a kit counts with its own physical stock only, not with what could be composed from its own subproducts. For example, if kit K needs 1 sub-kit S, S is made of 2 Y, S has no stock and Y has 10, K's composable stock is 0.
- **Stock column of the export**: composable stock for kits (the kit's own physical stock is not added: Dolibarr also moves the kit's stock when a kit is shipped, which would count the shipment twice), physical stock for other products. The **Physical stock** column gives the product's own physical stock in the same warehouses, for kits as well (a kit's physical stock can be negative, see above).
- **Combined stock option** (module setup, CSV export and export sent by email): a single stock column, with for kits the kit's physical stock (0 if negative) + composable stock, and for other products the physical stock. The Physical stock column is then not available.

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
