---
slug: secrets
title: Directories and secrets
category: Secrets
order: 40
---

# Directories and secrets

A secret is a value that should not be stored openly in chats, documents, or repositories. It may be a password, token, private key, connection string, or other sensitive text.

## Directories

Directories help keep things organized. A good structure makes secrets understandable without extra explanation.

- Separate environments: `Production`, `Staging`, `Development`.
- Separate purpose: `Databases`, `Monitoring`, `CI/CD`.
- Do not make the structure unnecessarily complex, otherwise the team will have trouble finding secrets.

## Secret names

The name should explain the purpose without exposing the value itself. For example, `Production PostgreSQL password` is clearer than `password1`.

## Revealing values

The secret value is hidden until you explicitly click the button to reveal it. If approval is enabled for the secret, a request will first be sent to the secret owner.

## Updating and deleting

Static secret values can be updated manually by clicking "Replace value" on the secret page. Secrets without templates can be replaced with an arbitrary value, while templated secrets can only be regenerated automatically.

After the value is updated, the secret version number will increase, and the history will show information about the update date.
