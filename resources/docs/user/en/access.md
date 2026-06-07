---
slug: access
title: Access and approvals
category: Secrets
order: 50
---

# Access and approvals

Access rules define who can see, reveal, and change secrets. Configure them according to the least-access principle.

## Reading and changing

For most workflows, it is important to separate permission to read a secret value from permission to change the secret record. A user may be allowed to view a value without being allowed to update or delete it.

## Inheritance

If a secret has no direct rule, it can use rules from its parent directory. This is useful when a whole group of related secrets needs the same access.

## Approvals

For critical secrets, you can require manual approval before reading. The user creates a request, and a responsible member approves or rejects it.

## Practice

- Grant access to groups when a role or team needs a secret.
- Grant access to a specific user for temporary or individual tasks.
- Check the audit log if access to a secret raises questions.
