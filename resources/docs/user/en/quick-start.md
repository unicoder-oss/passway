---
slug: quick-start
title: Quick start
category: Getting started
order: 20
---

# Quick start

Start using Passway and save your first secret.

## 1. Choose an organization

Open the organization you need from the home page. It usually corresponds to a team, project, or environment.

If the organization list is empty, ask an administrator to create an organization or send an invitation.

## 2. Create a directory

Directories help group secrets inside organizations. For example, you can create directories named `Production`, `Staging`, `Database`, or `External services`.

On the organization page, hover over the "+" icon and click "Folder...":
![Start creating a directory](/docs/images/en/folder_create_start.png)

A popup menu for creating a directory will open:
![Main directory creation flow](/docs/images/en/folder_create_continue.png)

Here you can enter any name and set the default access permissions for the directory.

After creation, the directory page will open, where you can add secrets and, if necessary, other directories.

## 3. Add a secret

Hover over the "+" icon and click "Secret...":
![Start creating a secret](/docs/images/en/secret_create_start.png)

In the window that appears, enter a name and select a secret type. For this example, create a static secret:
![Continue creating a secret](/docs/images/en/secret_create_continue.png)

Next, specify the secret value: enter it manually or use a ready-made template. In this example, enter the value manually:
![Finish creating a secret](/docs/images/en/secret_create_final.png)

> [!NOTE]
> At this step, you can also set the default access permissions for the secret. You can choose whether this secret can be read and edited. By default, the user's own role permissions are used, but you can explicitly allow or deny access to any organization member regardless of their role.

After the secret is created, its page will open, where you can reveal the value and perform actions on the secret:
![Secret page](/docs/images/en/secret_reveal.png)
