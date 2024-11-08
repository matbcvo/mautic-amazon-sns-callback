# Mautic Amazon SNS Callback

This Mautic 5 plugin integrates with Amazon SNS (Simple Notification Service) to handle bounce, complaint, and other notifications for email deliverability. By using this plugin, you can manage and process Amazon SES (Simple Email Service) feedback notifications directly within Mautic, helping maintain list health and ensuring accurate email analytics.

## Installation

To install the Mautic Amazon SNS Callback plugin, follow these steps:

1. Run the following command to add the plugin to your Mautic installation:
    ```bash
    composer require matbcvo/mautic-amazon-sns-callback
    ```

2. Clear the Mautic cache to ensure the plugin is recognized:
    ```bash
    php bin/console cache:clear
    ```

3. In Amazon SNS (Simple Notification Service):
    - Go to the SNS console and create a new topic by clicking on the **Create Topic** button.
    - After creating the topic, add a new subscription under the topic by clicking on **Create Subscription**.

4. Configure the new subscription:
    - For **Topic ARN**, select the topic you just created.
    - For **Protocol**, select HTTPS.
    - For **Endpoint**, enter the Mautic callback URL:
        ```
        https://mautic.example.com/mailer/callback
        ```

5. Click on **Create subscription**. The subscription status should automatically update to confirmed.


## Contributing

We welcome contributions! Please submit issues or pull requests as needed.
