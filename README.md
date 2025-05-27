# **LatePoint - ifthenpay Payment Gateway**
### 📘 User Guide 

Read in ![Portuguese](https://github.com/ifthenpay/WHMCS/raw/assets/version_8/assets/pt.png) [Portuguese](README_PT.md) or ![English](https://github.com/ifthenpay/WHMCS/raw/assets/version_8/assets/en.png) [English](README.md)

### 📌 Table of Contents

1. [Introduction 🚀](#1-introduction-🚀)
2. [Requirements 📋](#2-requirements-📋)
3. [Installation 📥](#3-installation-📥)
4. [Configuration ⚙️](#4-configuration-️)
5. [Customer Experience 🧑‍💻](#5-customer-experience-🧑‍💻)
6. [Language Support 🌍](#6-language-support-🌍)
7. [Support & Maintenance 🛠️](#7-support--maintenance-🛠️)

---

### 1. Introduction 🚀

**Ifthenpay** is a Portuguese digital payments provider established in 2004, specializing in omnichannel payment solutions. It seamlessly integrates with numerous ERPs, e-commerce platforms, and invoicing software, offering businesses a comprehensive approach to managing online financial transactions through diverse payment methods.

**LatePoint** is an intuitive and powerful appointment scheduling system designed for WordPress, empowering businesses to efficiently manage bookings and deliver a smooth scheduling experience to customers.

This plugin effectively integrates ifthenpay's payment gateway into LatePoint's checkout process, enabling secure, easy, and versatile online payments via:

- **PIX** 🇧🇷: Rapid Brazilian payment method utilizing QR codes or Pix keys.
- **BIZUM** 📲: Quick, mobile-based payment widely adopted in Spain.
- **MB WAY** 📱: Instant smartphone-based payments popular in Portugal.
- **Payshop** 💼: Fixed-value payment vouchers available at Portuguese retail outlets.
- **Multibanco** 🏧: Traditional Portuguese ATM network payments via reference numbers.
- **Credit Card (Visa & MasterCard)** 💳: Reliable and secure credit card payments.
- **Google Pay** 🌐: Effortless transactions through Google's digital wallet.
- **Apple Pay** 🍎: Secure payments via Apple devices.

This integration helps businesses using LatePoint to enhance customer satisfaction by providing trusted, efficient, and convenient payment solutions.

### 2. Requirements 📋

To successfully use the LatePoint ifthenpay plugin, ensure your environment meets these requirements:

- **WordPress:** Version 6.5 or higher.
- **LatePoint Plugin:** Installed and active on your WordPress site.
- **PHP:** Version 7.4 or higher.

Additionally, to integrate and utilize the ifthenpay payment gateway:

- An active **ifthenpay account** is required.
- Contact ifthenpay support to request your **Backoffice Key** and activate your **Gateway Keys**.
- Once a valid Backoffice Key is provided in the plugin settings, available Gateway Keys will automatically load.

For more information, visit the [ifthenpay official website](https://www.ifthenpay.com).

### 3. Installation 📥

Follow these steps to install the LatePoint ifthenpay Payment Gateway Plugin:

1. **Download the Plugin**

   - [Download](https://github.com/ifthenpay/latepoint-payment-addon/releases/download/v1.0.0/latepoint-payment-addon-v1.0.0.zip) the latest release of the plugin as a `.zip` file from the official repository.

![github-releases](./assets/github-releases.png)

2. **Upload to WordPress**

   - In your WordPress admin dashboard, go to **Plugins > Add New**.
   - Click **Upload Plugin**, select the downloaded `.zip` file, and click **Install Now**.

3. **Activate the Plugin**

   - After installation, click **Activate Plugin**.

4. **Verify LatePoint Installation**

   - Ensure the LatePoint plugin is installed and active, as this payment gateway requires it.

5. **Proceed to Configuration**
   - Once activated, configure the plugin as described in the following section.

Your plugin is now installed and ready for setup.

### 4. Configuration ⚙️

To configure the LatePoint ifthenpay Payment Gateway:

1. Go to your WordPress dashboard.
2. Navigate to **LatePoint > Settings > Payments**.
3. Under the **Payments** tab, activate the **ifthenpay Gateway** toggler, enter your **Backoffice Key** provided by ifthenpay and click **Connect**.

![latepoint_payment_settings](./assets/latepoint_payment_settings.png)

4. After validating the key, the plugin will automatically fetch available **Gateway Keys**. Select the key corresponding to the desired account.
5. Select and configure the available **Payment Methods**. For each method, use the checkbox to activate or deactivate it, and select the associated payment account from the dropdown.
6. Choose a **Default Payment Method** to streamline user checkout.
7. Optionally add a custom **Description** to appear during checkout.

![ifthenpay_admin_config](./assets/ifthenpay_admin_config.png)

8. Save your configuration.

### 5. Customer Experience 🧑‍💻

1. **Choose Payment Method**

   During booking, if payments are enabled, customers select a payment method. They can choose **ifthenpay Gateway** and proceed to pay securely.

![ifthenpay_admin_checkout](./assets/ifthenpay_select_checkout.png)

2. **Secure Payment Page**

   They are redirected to a secure ifthenpay page to pay using their preferred method (e.g., Google Pay, credit card).

![ifthenpay_gateway](./assets/ifthenpay_gateway.png)

3. **Confirmation & Return**

   After completing the payment, customers are returned to your site. Their appointment is confirmed, and payment status is reflected immediately in LatePoint.

![latepoint_booking_confirmed](./assets/booking_confirmed.png)

### 6. Language Support 🌍

This plugin currently supports the following languages:

- 🇵🇹 **Portuguese (Portugal)** — `pt-PT`
- 🇪🇸 **Spanish (Spain)** — `es-ES`
- 🇬🇧 **English (UK)** — `en-UK`
- 🇫🇷 **French (France)** — `fr-FR`

The plugin will automatically adapt its text and interface to match the language configured in your WordPress settings, ensuring a seamless experience for both admins and customers.

### 7. Support & Maintenance 🛠️

If you encounter issues or need assistance, please refer to the following resources:

- 📖 [Official ifthenpay FAQ](https://helpdesk.ifthenpay.com/en/support/home)
- 📬 Support Email: `suporte@ifthenpay.com`
- 🧰 [LatePoint Knowledge Base](https://wpdocs.latepoint.com/)

#### Keeping the Plugin Updated:

- Always use the latest versions of WordPress, LatePoint, and this plugin.
- Review the changelog before updating.
- After updates, test payment flows to ensure functionality.

Regular updates and active monitoring will ensure continued compatibility and optimal performance.
