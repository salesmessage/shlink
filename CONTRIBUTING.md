# Contributing

This file will guide you through the process of getting to project up and running, in case you want to provide coding contributions.

You will also see how to ensure the code fulfills the expected code checks, and how to create a pull request.

## System dependencies

The project provides all its dependencies as docker containers through a docker-compose configuration.

Because of this, the only actual dependencies are [docker](https://docs.docker.com/get-docker/) and [docker-compose](https://docs.docker.com/compose/install/).

## Setting up the project

The first thing you need to do is fork the repository, and clone it in your local machine.

Then you will have to follow these steps:

* Copy all files with `.local.php.dist` extension from `config/autoload` by removing the dist extension.

    For example the `common.local.php.dist` file should be copied as `common.local.php`.

* Copy the file `docker-compose.override.yml.dist` by also removing the `dist` extension.
* Start-up the project by running `docker-compose up`.

    The first time this command is run, it will create several containers that are used during development, so it may take some time.

    It will also create some empty databases and install the project dependencies with composer.

* Run `./indocker bin/cli db:create` to create the initial database.
* Run `./indocker bin/cli db:migrate` to get database migrations up to date.
* Run `./indocker bin/cli api-key:generate` to get your first API key generated.

Once you finish this, you will have the project exposed in ports `8000` through nginx+php-fpm and `8080` through openswoole.

> Note: The `indocker` shell script is a helper tool used to run commands inside the main docker container.

## Project structure

This project is structured as a modular application, using [laminas/laminas-config-aggregator](https://github.com/laminas/laminas-config-aggregator) to merge the configuration provided by every module.

All modules are inside the `module` folder, and each one has its own `src`, `test` and `config` folders, with the source code, tests and configuration. They also have their own `ConfigProvider` class, which is consumed by the config aggregator.

This is a simplified version of the project structure:

```
shlink
├── bin
│   └── cli
├── config
│   ├── autoload
│   ├── params
│   ├── config.php
│   └── container.php
├── data
│   ├── cache
│   ├── locks
│   ├── log
│   ├── migrations
│   └── proxies
├── docs
│   ├── adr
│   ├── async-api
│   └── swagger
├── module
│   ├── CLI
│   ├── Core
│   └── Rest
├── public
├── composer.json
└── README.md
```

The purposes of every folder are:

* `bin`: It contains the CLI tools. The `cli` one is the main entry point to run shlink from the command line.
* `config`: Contains application-wide configurations, which are later merged with the ones provided by every module.
* `data`: Common runtime-generated git-ignored assets, like logs, caches, etc.
* `docs`: Any project documentation is stored here, like API spec definitions or architectural decision records.
* `module`: Contains a sub-folder for every module in the project. Modules contain the source code, tests and configurations for every context in the project.
* `public`: Few assets (like `favicon.ico` or `robots.txt`) and the web entry point are stored here. This web entry point is not used when serving the app with openswoole.

## Project tests

In order to ensure stability and no regressions are introduced while developing new features, this project has unit tests.

* **Unit tests**: These test individual pieces of code, replacing any external dependency by mocks.

    This fork runs no other suite. Upstream's database, API and CLI test suites have been removed, so behaviour that would have been covered end to end is covered here instead, with the database and any HTTP collaborator mocked.

Depending on the kind of contribution, maybe not all kinds of tests are needed, but the more you provide, the better.

## Running code checks

* Run `./indocker_test cs` to check coding styles are fulfilled.
* Run `./indocker_test cs:fix` to fix coding styles (some may not be fixable from the CLI)
* Run `./indocker_test stan` to statically analyze the code with [phpstan](https://phpstan.org/). This tool is the closest to "compile" PHP and verify everything would work as expected.
* Run `./indocker_test` to run the unit tests. Add phpunit arguments to narrow the run down, for example `./indocker_test --filter SomeTest`.
* Run `./indocker_test infect` to run the unit tests and then apply mutations to them with [infection](https://infection.github.io/).
* Run `./indocker_test ci` to run all previous commands together, parallelizing non-conflicting tasks as much as possible.

See the [Testing](README.md#testing) section of the README for the full list of targets and options.

## Pull request process

**Important!**: Before starting to work on a pull request, make sure you always [open an issue](https://github.com/shlinkio/shlink/issues/new/choose) first.

This is important because any contribution needs to be discussed first. Maybe there's someone else already working on something similar, or there are other considerations to have in mind.

Once everything is clear, to provide a pull request to this project, you should always start by creating a new branch, where you will make all desired changes.

The base branch should always be `develop`, and the target branch for the pull request should also be `develop`.

Before your branch can be merged, all the checks described in [Running code checks](#running-code-checks) have to be passing. You can verify that manually by running `./indocker composer ci`, or wait for the build to be run automatically after the pull request is created.

## Architectural Decision Records

The project includes logs for some architectural decisions, using the [adr](https://adr.github.io/) proposal.

If you are curious or want to understand why something has been built in some specific way, [take a look at them](docs/adr).
