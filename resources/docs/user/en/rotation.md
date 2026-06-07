---
slug: rotation
title: Generation, templates, and rotation
category: Secrets
order: 60
---

# Generation, templates, and rotation

You can store not only arbitrary text values but also generate new secrets automatically from templates. This reduces the risk of weak or reused values, such as passwords.

## Templates

A template defines generation rules. Currently, templates are available for generating passwords and SSH key pairs (Ed25519 and RSA-4096).

## When to use generation

- A new service password is needed.
- Manual selection of a weak value should be avoided.
- The team wants consistent requirements for length and characters.

## Rotation

Rotation means replacing a secret with a new value. It is useful after an incident, after team membership changes, or on a regular schedule.

> [!NOTE]
> Before rotation, check which systems use the secret. After the value changes, they may need to be updated. Instead of replacing values manually after rotation, you can use the REST API so applications and scripts can retrieve the current secret value.

## Version history

Passway keeps information about versions and actions on a secret. This helps understand when a value changed and how.
