# EspoCRM Google Workspace SSO & Background Sync Extension

This extension integrates Google Workspace Single Sign-On (SSO) and background synchronization as a native authentication and user management method for EspoCRM v9.x.

## Features

- **Google Workspace Native Integration**: Allow users to log in directly using their Google Workspace accounts.
- **Admin Configuration**: Completely manageable from the EspoCRM Administration panel (Settings > Authentication).
- **Domain Restriction**: Restrict logins exclusively to users belonging to a specific Hosted Domain (e.g., yourcompany.com), rejecting external users automatically.
- **Auto-create Users**: Optionally autocreate EspoCRM users upon successful SSO login for accounts that do not already exist in the CRM database.
- **Granular Synchronization**: Automatically download and synchronize the user's Google Workspace profile picture, names, emails, and phone numbers.
- **Background Sync via Cron**: Synchronize users from Google Workspace using a background job and a custom Google Service Account securely.
- **Sync Google Groups (Optional)**: Automatically import Google Groups and map them to Teams in EspoCRM natively. Toggleable via settings.
- **Whitelist Groups**: Filter the background sync to import only users belonging to a specific list of Google Workspace groups.
- **Sync on Login**: Instantly trigger an API sync of the user's profile data whenever they log in via SSO.
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
   - **Auto-create Users (Optional)**: If enabled, users successfully authenticated via Google Workspace but missing in EspoCRM will be automatically created.
   - **Allow Administrator fallback**: Whether or not administrators can still fall back to logging in using a username and password.
   - **Allow Regular User fallback**: Whether or not regular users can still fall back to logging in using a username and password.
   - **Sync Avatar (Optional)**: If enabled, EspoCRM will dynamically sync the user's avatar from their Google Workspace profile upon login and background sync.
   - **Sync Names, Emails, Phones**: Independently toggle updates for specific user attributes.
   - **Sync Active Status (Optional)**: If enabled, the user's active/suspended status in Google Workspace will be synchronized to EspoCRM.
   - **Sync Google Groups (Optional)**: If enabled, Google Groups will be fetched and synchronized as native EspoCRM Teams. Disabled by default.
   - **Sync user on login**: When a user logs in via SSO, trigger a live API call to fetch their latest Workspace profile data instantly.
   - **Enable background sync**: Toggle the overall cron synchronization execution.
   - **Background Sync Frequency**: Choose how often the background synchronization should run (15 minutes, 1 hour, 2 hours, 12 hours, or 24 hours).
   - **Google Administrator Email**: The email of an Administrator account in the Workspace to impersonate for Directory API calls.
   - **Google Service Account JSON**: The JSON key payload created for a Google Service Account used for background synchronization.
   - **Whitelist User Groups**: Add one or more Google Workspace group emails here. Only users belonging to these groups will be periodically imported/synced by the background task.

### 3. Background Sync Configuration
To automatically sync Users and Teams from Google Workspace to EspoCRM without requiring the users to log in first:

**A. Create & Setup the Service Account (Google Cloud):**
1. Access the [Google Cloud Console](https://console.cloud.google.com/).
2. Select your project and go to **APIs & Services > Library**. Search for **Admin SDK API** and click **Enable**.
3. Head to **IAM & Admin > Service Accounts**.
4. Click **Create Service Account**, give it a name (e.g., `espocrm-sync-sa`), and create it.
3. Once created, click on it, go to the **Keys** tab, and select **Add Key > Create new key > JSON**. The JSON file will download to your computer. Open this file and copy its entire contents: this is your **Google Service Account JSON** payload.
4. Go back to the Service Account details page and copy its **Unique ID** (Client ID) or email.

**B. Enable Domain-Wide Delegation (Google Workspace Admin):**
1. Go to the [Google Workspace Admin Console](https://admin.google.com/).
2. Navigate to **Security > Access and data control > API controls**.
3. Under *Domain-wide delegation*, click **Manage Domain Wide Delegation**.
4. Click **Add new**.
5. Paste the **Client ID** (Unique ID) you copied from the Service Account.
6. In the **OAuth Scopes (comma-separated)** field, you **MUST** paste exactly these three scopes:
   - `https://www.googleapis.com/auth/admin.directory.user.readonly`
   - `https://www.googleapis.com/auth/admin.directory.group.readonly`
   - `https://www.googleapis.com/auth/admin.directory.group.member.readonly`
7. Click **Authorize**.

**C. Finalize in EspoCRM:**
1. In EspoCRM Administration > Settings > Authentication (Google Workspace settings), make sure **Enable background sync** is checked.
2. Paste the JSON key text into the **Google Service Account JSON** field.
3. Provide your **Google Administrator Email**. The Service Account uses this email to impersonate an administrator when querying the Directory API.
4. Save the EspoCRM settings.

**D. Schedule the Cron Job:**
1. Go to EspoCRM **Administration > Scheduled Jobs**.
2. Assuming `cron` is properly configured on your server, look for the newly available Job titled `Sync Google`.
3. Set its status to **Active** and configure its Scheduling (e.g., `0 * * * *` to run every hour).

## Building from source

To generate an installable zip package, ensure you have `node`, `npm`, and `composer` globally installed:

```bash
npm install
npm run extension
```

The extension `.zip` package will be created in the `build/` directory.

## Testing & Quality Assurance

This extension is built with high engineering standards to ensure it flawlessly connects with the core of EspoCRM.

- **PHPStan Level 8**: The entire codebase respects strict typing rules (`npm run sa`).
- **PHPUnit Coverage**: Over 14 unit tests (with +68 assertions) validating the ORM relations and data mapping securely. You can run them via `npm run unit-tests`.

## License

This project is licensed under the [MIT License](LICENSE).
Copyright (c) 2026 Metrodora APS
