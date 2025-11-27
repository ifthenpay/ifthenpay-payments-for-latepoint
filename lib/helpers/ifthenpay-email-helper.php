<?php
if (! defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}

class IfthenpayEmailHelper
{
   const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

   public function __construct() {}

   /**
    * Sends activation email to support.
    *
    * @param array $data {
    *   @type string gateway_key      Gateway key.
    *   @type string entity           Method/entity to activate.
    *   @type string backoffice_key   Backoffice access key.
    *   @type string customer_email   Customer's email.
    *   @type string site_url         Store URL.
    *   @type string site_name        Site name.
    *   @type string wp_version       WordPress version.
    *   @type string latepoint_version LatePoint version.
    *   @type string plugin_version   ifthenpay module version.
    * }
    * @return bool True if sent successfully, false otherwise.
    */
   public static function send_activation_email(array $data): bool
   {
      // Build email subject
      $subject = sprintf(
         '[dev_ifthenpay] [%s]: Ativação de Serviço',
         strtoupper(sanitize_text_field($data['entity']))
      );

      // Email body items
      $items = [
         'Chave de acesso ao backoffice:' => esc_html($data['backoffice_key']),
         'Gateway Key:'                   => esc_html($data['gateway_key']),
         'Email Cliente:'                 => esc_html($data['customer_email']),
         'Método a ativar:'               => esc_html(strtoupper($data['entity'])),
         'Loja online:'                   => esc_url($data['site_url']),
         'Plataforma ecommerce:'          => sprintf(
            'WordPress %s / LatePoint v%s',
            esc_html($data['wp_version']),
            esc_html($data['latepoint_version'])
         ),
         'Versão do Módulo ifthenpay:'    => esc_html($data['plugin_version']),
         'Atualizar Conta Cliente:'       => 'Após adicionar o método não precisa tomar mais nenhuma ação, este método ficará disponível para seleção na página de configuração da extensão.',
      ];

      // HTML Body
      ob_start();
?>
      <div style="
    font-family: Arial, sans-serif;
    color: #333;
    background-color: #f9f9f9;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    max-width: 600px;
    margin: auto;
">
         <h2 style="
      margin-top: 0;
      font-size: 20px;
      line-height: 1.2;
    ">
            Ativar método de pagamento para a Gateway
            <span style="color: #d32f2f;">
               <?php echo esc_html($data['gateway_key']); ?>
            </span>
         </h2>

         <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse: collapse;">
            <?php foreach ($items as $label => $value) : ?>
               <tr>
                  <td style="
        padding: 8px 0;
        vertical-align: top;
        width: 200px;
        font-weight: bold;
      ">
                     <?php echo esc_html($label); ?>
                  </td>
                  <td style="
        padding: 8px 0;
      ">
                     <?php echo esc_html($value); ?>
                  </td>
               </tr>
            <?php endforeach; ?>
         </table>

         <p style="
      margin-top: 20px;
      font-size: 12px;
      color: #777;
      text-align: center;
    ">
            Pedido gerado automaticamente pelo módulo ifthenpay
         </p>
      </div>
<?php
      $body = ob_get_clean();

      // Headers
      $host = wp_parse_url($data['site_url'], PHP_URL_HOST);
      $headers = [
         'Content-Type: text/html; charset=UTF-8',
         'From: ' . esc_html($data['site_name']) . ' <no-reply@' . $host . '>',
      ];

      // Send email
      return wp_mail(self::SUPPORT_EMAIL, $subject, $body, $headers);
   }
}
