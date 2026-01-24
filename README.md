![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7%20%7C%208.x-blue)
![License](https://img.shields.io/badge/License-MIT-green)
![Release](https://img.shields.io/github/v/release/iamadlx/PrestaShop-LLMS-Generator?label=Latest%20release)
![Downloads](https://img.shields.io/github/downloads/iamadlx/PrestaShop-LLMS-Generator/total?label=Downloads)

# PrestaShop LLMS Generator

A minimal, update-proof **/llms.txt** generator for PrestaShop.
It creates a single **multi-language** `llms.txt` file at your store root, including:
- CMS pages (with an easy exclude checklist)
- Categories
- Products (all active products, no limit)

✅ No theme edits (safe for theme updates)  
✅ Back-office configuration (multi-language title/description)  
✅ Generates on Save (1 click)  
✅ Works with multi-language shops (FR/EN/NL, etc.)

## Download

Get the latest version from the **Releases** page:
https://github.com/iamadlx/PrestaShop-LLMS-Generator/releases

## Hosting recommendation (optional)

If you're looking for a hosting provider with **1-click PrestaShop installation**, easy maintenance, and automated backups, Infomaniak offers a dedicated PrestaShop hosting plan:

- **1-click installation** + easy management  
- **Easy updates** + protection against known security vulnerabilities  
- **Automatic backups & restore**

👉 [https://www.infomaniak.com/fr/creer-un-site/cms/hebergement-prestashop](https://www.infomaniak.com/fr/creer-un-site/cms/hebergement-prestashop?utm_term=5f980010e7533)

## Installation (30 seconds)

1. Download the latest release zip.
2. In PrestaShop Back Office: **Modules → Module Manager → Upload a module**
3. Upload the zip and install it.

## Setup

1. Go to **Modules → PrestaShop LLMS Generator → Configure**
2. Set your **Site title** and **Global description** (multi-language fields)
3. Choose what to include:
   - CMS pages (and uncheck the ones you want to exclude)
   - Categories
   - Products
4. Click **Save**
5. Open: `https://your-domain.com/llms.txt`

## Notes

- The back-office interface follows your shop/BO language.
- The generated `llms.txt` includes a section for each active shop language.

## License

MIT
