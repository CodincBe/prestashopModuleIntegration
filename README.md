# Prestashop Module Integration Helper
This project focuses on getting a basic toolset to improve module integration on Prestashop.

At a base it started with getting modules converted from a Prestashop 1.6 to a 1.7 way of working.

## Usage
This package introduces a command to assist with upgrading the database based on the definitions given in a certain module.

```$xslt
php app/console codinc:module:upgrade:database {moduleName}
```

Once triggered you will first get an output of the changes in the database that need to be executed to support the module
followed with a question to apply them immediately or not.
