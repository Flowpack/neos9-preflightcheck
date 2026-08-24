# Neos 9 - Preflight Check

Install this package in your Neos 8.4 to check the readiness of your CR for the migration to Neos 9.

With Neos 9 the CR have become stricter to ensure a consistent state. Therefore, the old CR must 
be in a clean state before migration. This tool helps you to check your CR in advance. 

## Installation

**Requirements:** Neos 8.4 and PHP >=8.2


```bash
composer require --dev flowpack/neos9-preflightcheck
```

## Usage

```bash
./flow preflight:check
```

## Tests

* Custom node implementations
* Ambiguously typed node aggregates
* Dedicated site node type (subtype of Neos.Neos:Site)
* Variants with different parents _(only Neos <9.3)_


