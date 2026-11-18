<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copie facture {{ $invoiceNumber }}</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #f2f2f2; padding: 20px; border-radius: 8px; text-align: center; border: 1px solid #e9ecef;">
                            <h1 style="color: #333333; margin: 0 0 10px 0; font-size: 20px;">{{ $platformName }} - Espace Vendeur</h1>
                            <p style="color: #333333; margin: 0;">Copie de facture pour votre vente</p>
                        </td>
                    </tr>

                    <tr><td height="30"></td></tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                            <h2 style="color: #333333; margin-top: 0;">Bonjour {{ $vendorName }},</h2>
                            
                            <p style="color: #333333; line-height: 1.6;">
                                Une nouvelle vente a été finalisée sur {{ $platformName }}. Voici la copie de la facture émise.
                            </p>

                            <!-- Sale details -->
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 5px; border: 1px solid #e9ecef; margin: 20px 0;">
                                <tr>
                                    <td style="color: #333333;">
                                        <p style="margin: 5px 0;">
                                            <strong>Facture :</strong> 
                                            <span style="color: #000000; font-weight: bold;">{{ $invoiceNumber }}</span>
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Commande :</strong> {{ $orderNumber }}
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Client :</strong> {{ $customerName }}
                                        </p>
                                        <p style="margin: 5px 0;">
                                            <strong>Montant total :</strong> {{ $formattedAmount }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Amounts breakdown -->
                            <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f2f2f2; border-radius: 5px; border: 1px solid #e9ecef; margin: 20px 0;">
                                <tr>
                                    <td style="color: #333333;">
                                        <h3 style="margin-top: 0;">Répartition des montants :</h3>
                                        
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #333333; padding: 5px 0;">Montant de la vente :</td>
                                                <td align="right" style="color: #333333; padding: 5px 0;">{{ $formattedAmount }}</td>
                                            </tr>
                                            <tr>
                                                <td style="color: #333333; padding: 5px 0;">Commission plateforme ({{ number_format($platformFeeRate * 100, 1) }}%) :</td>
                                                <td align="right" style="color: #333333; padding: 5px 0;">- {{ number_format($platformFee, 2, ',', ' ') }} €</td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="border-top: 1px solid #e9ecef; padding-top: 10px;"></td>
                                            </tr>
                                            <tr>
                                                <td style="color: #000000; font-weight: bold; padding: 5px 0;">Votre part :</td>
                                                <td align="right" style="color: #000000; font-weight: bold; padding: 5px 0;">{{ number_format($vendorAmount, 2, ',', ' ') }} €</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="color: #333333; line-height: 1.6;">
                                <strong>Le transfert de votre part sera effectué selon les modalités convenues.</strong>
                            </p>

                            <p style="color: #333333; line-height: 1.6;">
                                La facture complète est jointe à cet email pour vos archives.
                            </p>

                            <h3 style="color: #333333;">Questions ?</h3>
                            <p style="color: #333333; line-height: 1.6;">
                                Pour toute question concernant cette vente ou les paiements :
                            </p>
                            <p style="color: #333333;">
                                📧 Email : <a href="mailto:{{ $supportEmail }}" style="color: #000000; text-decoration: none;">{{ $supportEmail }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr><td height="30"></td></tr>

                    <!-- Footer -->
                    <tr>
                        <td style="text-align: center; padding-top: 20px; border-top: 1px solid #e9ecef; color: #888888; font-size: 14px;">
                            <p style="margin: 5px 0;">{{ $platformName }} - Espace Vendeur</p>
                            <p style="margin: 5px 0;">Cet email a été envoyé automatiquement.</p>
                            <p style="margin: 5px 0;">Support vendeur : {{ $supportEmail }}</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>