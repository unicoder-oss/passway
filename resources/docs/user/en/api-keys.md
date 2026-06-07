---
slug: api-keys
title: API keys
category: Automation
order: 70
---

# API keys

API keys are used for automation: CI/CD, internal tools, scripts, and services that need to read or update data without a user signing in through the browser.

## When to create an API key

- A script needs to read a secret during deployment.
- An application must create or update records according to a specific algorithm.

## How to handle a key

An API key is itself a secret. Do not store it in an open repository, send it in chat, or paste it into public settings.

## Limit permissions

Give an API key only the permissions required for its task. If it needs to read one directory, do not grant access to the whole organization.

> [!NOTE]
> If an API key is no longer needed or may have been exposed, revoke it and create a new one.

## API reference

Detailed request examples are available on the [API](/api) page. It is useful when you need to connect automation to Passway.
