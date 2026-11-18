<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Réinitialisation du mot de passe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4; font-family:Arial, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center" style="padding:20px;">
                <!-- Container -->
                <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#ffffff; border-radius:8px; overflow:hidden;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#000000; padding:20px; color:#ffffff; text-align:center;">
                            <h1 style="margin:0; font-size:24px;">Réinitialisez votre mot de passe</h1>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:30px; color:#333333; font-size:16px; line-height:1.5;">
                            <p>Bonjour {{ $user->first_name }},</p>
                            <p>Vous avez demandé à réinitialiser votre mot de passe. Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe :</p>
                            
                            <!-- Button -->
                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                <tr>
                                    <td align="center" style="padding:20px 0;">
                                        <a href="{{ $resetUrl }}" style="
                                            display:inline-block;
                                            padding:12px 24px;
                                            background-color:#000000;
                                            color:#ffffff;
                                            text-decoration:none;
                                            border-radius:4px;
                                            font-size:16px;
                                        ">Réinitialiser mon mot de passe</a>
                                    </td>
                                </tr>
                            </table>

                            <p>Ce lien est valable <strong>1 heure</strong>. Après expiration, vous devrez en demander un nouveau.</p>
                            <p>Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer ce message sans autre action.</p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f9fafb; padding:20px; text-align:center; font-size:12px; color:#888888;">
                            <p style="margin:0;">&copy; {{ date('Y') }} {{ config('app.name') }}. Tous droits réservés.</p>
                        </td>
                    </tr>

                </table>
                <!-- /Container -->
            </td>
        </tr>
    </table>
</body>
</html>
