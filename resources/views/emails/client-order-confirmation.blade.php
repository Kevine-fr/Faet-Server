<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation de commande</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #000000; padding: 20px; border-radius: 8px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0 0 10px 0; font-size: 20px;">{{ config('app.name') }}</h1>
                            <p style="color: #efefef; margin: 0;">✅ Paiement confirmé</p>
                        </td>
                    </tr>

                    <tr><td height="30"></td></tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                            <h2 style="color: #333333; margin-top: 0;">Bonjour {{ $emailData['customer_name'] }},</h2>
                            
                            <p style="color: #333333; line-height: 1.6;">
                                Merci pour votre achat ! Votre paiement a été confirmé avec succès le <strong>{{ $emailData['payment_date'] }}</strong>.
                            </p>

                            <!-- Orders Summary -->
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 5px; border: 1px solid #e9ecef; margin: 20px 0;">
                                <tr>
                                    <td style="color: #333333;">
                                        <h3 style="margin-top: 0;">
                                            @if($emailData['orders_count'] > 1)
                                                Vos {{ $emailData['orders_count'] }} commandes
                                            @else
                                                Votre commande
                                            @endif
                                        </h3>
                                        
                                        @foreach($emailData['orders'] as $order)
                                        <div style="background-color: white; padding: 15px; border-radius: 5px; margin-bottom: 10px; border-left: 3px solid #000000;">
                                            <p style="margin: 5px 0;">
                                                <strong>Commande :</strong> 
                                                <a href="{{ $order['order_url'] }}" style="color: #000000; text-decoration: none; font-weight: bold;">
                                                    {{ $order['order_number'] }}
                                                </a>
                                            </p>
                                            <p style="margin: 5px 0;">
                                                <strong>Vendeur :</strong> {{ $order['name_store'] }}
                                            </p>
                                            <p style="margin: 5px 0;">
                                                <strong>Articles :</strong> {{ $order['items_count'] }} article{{ $order['items_count'] > 1 ? 's' : '' }}
                                            </p>
                                            <p style="margin: 5px 0;">
                                                <strong>Montant :</strong> 
                                                <span style="font-weight: bold; color: #000000;">
                                                    {{ number_format($order['total_amount'], 2, ',', ' ') }} €
                                                </span>
                                            </p>
                                            <p style="margin: 10px 0 0 0;">
                                                <a href="{{ $order['order_url'] }}" style="display: inline-block; background-color: #000000; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-size: 14px;">
                                                    Voir les détails
                                                </a>
                                            </p>
                                        </div>
                                        @endforeach

                                        <div style="border-top: 2px solid #e9ecef; margin-top: 15px; padding-top: 15px;">
                                            <p style="margin: 0; font-size: 18px; color: #000000;">
                                                <strong>Total payé :</strong> 
                                                <span style="font-weight: bold;">
                                                    {{ number_format($emailData['total_amount'], 2, ',', ' ') }} €
                                                </span>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Facture Info -->
                            <div style="background-color: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 5px; padding: 15px; margin: 20px 0;">
                                <p style="margin: 0 0 10px 0; color: #1e40af; font-weight: bold;">
                                    📄 Vos factures sont disponibles
                                </p>
                                <p style="margin: 0 0 15px 0; color: #1e40af; font-size: 14px;">
                                    Téléchargez vos factures directement depuis la page de détails de chaque commande.
                                </p>
                                <a href="{{ $emailData['orders_list_url'] }}" style="display: inline-block; background-color: #1e40af; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">
                                    Accéder à mes commandes
                                </a>
                            </div>

                            <h3 style="color: #333333;">Prochaines étapes</h3>
                            <ul style="color: #333333; line-height: 1.8; padding-left: 20px;">
                                <li>Le(s) vendeur(s) prépar{{ $emailData['orders_count'] > 1 ? 'ent' : 'e' }} votre/vos commande(s)</li>
                                <li>Vous recevrez un email lorsque votre/vos commande(s) ser{{ $emailData['orders_count'] > 1 ? 'ont' : 'a' }} prête(s)</li>
                                <li>Suivez l'état de vos commandes depuis votre espace client</li>
                            </ul>

                            <div style="text-align: center; margin-top: 30px;">
                                <a href="{{ $emailData['orders_list_url'] }}" style="display: inline-block; background-color: #000000; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 16px;">
                                    Voir mes commandes
                                </a>
                            </div>

                            <h3 style="color: #333333; margin-top: 30px;">Besoin d'aide ?</h3>
                            <p style="color: #333333; line-height: 1.6;">
                                Notre équipe est là pour vous aider :
                            </p>
                            <p style="color: #333333;">
                                📧 <a href="mailto:{{ $emailData['support_email'] }}" style="color: #000000; text-decoration: none;">{{ $emailData['support_email'] }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr><td height="30"></td></tr>

                    <!-- Footer -->
                    <tr>
                        <td style="text-align: center; padding-top: 20px; border-top: 1px solid #e9ecef; color: #888888; font-size: 14px;">
                            <p style="margin: 5px 0;">{{ config('app.name') }}</p>
                            <p style="margin: 5px 0;">Cet email a été envoyé automatiquement, merci de ne pas y répondre directement.</p>
                            <p style="margin: 5px 0;">Pour toute question : {{ $emailData['support_email'] }}</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>