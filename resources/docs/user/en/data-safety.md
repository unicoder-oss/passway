---
slug: data-safety
title: How Passway stores data
category: Security
order: 80
---

# How Passway stores data

Passway is designed for sensitive values, so secrets should not be stored in the database as ordinary text. Here is a short explanation without internal technical detail.

## Secrets are encrypted

Secret values are saved in encrypted form. This means the database stores a protected representation, not the password or token as plain text.

## Access is checked before actions

Before viewing or changing data, Passway checks whether the user, group, or API key has the required permission. If permission is missing, the value is not revealed.

## Actions are logged

Important actions are written to the audit log. This helps notice unusual activity and understand who worked with secrets.

> [!NOTE]
> Passway helps store secrets safely, but security still depends on user habits: strong passwords, two-factor verification, careful handling of revealed values, and timely removal of unnecessary access.

## What not to do

- Do not copy secrets into documents or tasks.
- Do not reuse one password across services.
- Do not grant access “just in case”.
