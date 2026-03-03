# EspoCRM Google Workspace SSO Authentication Extension

This extension integrates Google Workspace Single Sign-On (SSO) as a native authentication method for EspoCRM v9.x.

## Features

- **Google Workspace Native Integration**: Allow users to log in directly using their Google Workspace accounts.
- **Admin Configuration**: Completely manageable from the EspoCRM Administration panel (Settings > Authentication).
- **Domain Restriction**: Restrict logins exclusively to users belonging to a specific Hosted Domain (e.g., yourcompany.com), rejecting external users automatically.
- **Auto-create Users**: Optionally autocreate EspoCRM users upon successful SSO login for accounts that do not already exist in the CRM database.
- **Avatar Synchronization**: Automatically download and synchronize the user's Google Workspace profile picture into EspoCRM.
- **Fallback Authentication**: Flexible options to allow a standard username & password login fallback alongside Google Workspace authentication, configurable independently for Administrators and Regular Users.

## Supported Versions

* EspoCRM: >= 9.3.0
* PHP: >= 8.3

## Installation

1. Navigate to Administration > Extensions.
2. Upload the `google-workspace-x.x.x.zip` package.
3. Once the installation is complete, navigate to Administration > Clear Cache and clear the system cache.

## Configuration

### 1. Set up OAuth in Google Cloud Console
1. Access the [Google Cloud Console](https://console.cloud.google.com/).
2. Head to **APIs & Services > Credentials**.
3. Create a new OAuth 2.0 Client ID (Application Type: Web Application).
4. For the **Authorized redirect URIs**, add the specific Redirect URI shown in the EspoCRM Authentication settings.

### 2. Configure EspoCRM
1. Go to **Administration > Settings**.
2. Locate the **Authentication Method** dropdown and select `Google Workspace`.
3. Provide the required parameters:
   - **Google Workspace Client ID**: Your OAuth 2.0 Client ID.
   - **Google Workspace Client Secret**: Your OAuth 2.0 Client Secret.
   - **Redirect URI**: The URL to provide to Google Cloud Console for the OAuth client.
   - **Hosted Domain (Optional)**: If you specify a domain (like `company.com`), only users matching this domain will be able to log in.
   - **Auto-create Users (Optional)**: If enabled, users successfully authenticated via Google Workspace but missing in EspoCRM will be automatically created (name, surname, email).
   - **Allow Administrator fallback**: Whether or not administrators can still fall back to logging in using a username and password.
   - **Allow Regular User fallback**: Whether or not regular users can still fall back to logging in using a username and password.
   - **Sync Avatar (Optional)**: If enabled, EspoCRM will dynamically sync the user's avatar from their Google Workspace profile upon login.

## Building from source

To generate an installable zip package, ensure you have `node`, `npm`, and `composer` globally installed:

```bash
npm install
npm run extension
```

The extension `.zip` package will be created in the `build/` directory.

## License

This project is licensed under the [MIT License](LICENSE).
Copyright (c) 2026 Metrodora APS
