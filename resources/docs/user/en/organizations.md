---
slug: organizations
title: Organizations and members
category: Team work
order: 30
---

# Organizations and members

An organization separates one team or project's data from others. It contains directories, secrets, members, groups, API keys, and audit logs.

## When to create a separate organization

- A project has its own team and secrets.
- Secrets for development and release need to be separated.
- Different groups of people should not share access to the same data.

## Members

A member can access only the organizations they belong to. Inside an organization, their capabilities depend on their role and access rules for directories and secrets.

## Groups

Groups are useful when several people need the same permissions. Instead of configuring every user separately, grant access to a group such as `Backend`, `DevOps`, or `Support`.

> [!NOTE]
> Review the member list regularly. If someone no longer works with a project, it is better to promptly close their access to the group or the entire organization.
