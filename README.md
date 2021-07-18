# Pre-built repository base classes.

This offers some base classes that you can inherit from, although they are not strictly necessary.

## Installation

#### Library

```bash
git clone https://github.com/ntd1712/repository.git
```

#### Composer

This can be installed with [Composer](https://getcomposer.org/doc/00-intro.md)

Define the following requirement in your `composer.json` file.

```json
{
    "require": {
        "chaos/repository": "*"
    },

    "repositories": [
      {
        "type": "vcs",
        "url": "https://github.com/ntd1712/repository"
      }
    ]
}
```
