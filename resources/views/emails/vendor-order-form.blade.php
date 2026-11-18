<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon de commande {{ $form_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
        }
        .container {
            padding: 20px;
        }
        .header {
            border-bottom: 3px solid #10b981;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 24pt;
            font-weight: bold;
            color: #10b981;
            margin-bottom: 5px;
        }
        .company-info {
            font-size: 9pt;
            color: #666;
        }
        .vendor-section {
            background-color: #f0fdf4;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #10b981;
        }
        .vendor-section h3 {
            font-size: 11pt;
            margin-bottom: 8px;
            color: #166534;
        }
        .order-block {
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        .order-header {
            background-color: #f9fafb;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .order-header h4 {
            font-size: 12pt;
            color: #000;
            margin-bottom: 5px;
        }
        .customer-info {
            font-size: 9pt;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        table.items {
            border: 1px solid #ddd;
        }
        table.items th {
            background-color: #f5f5f5;
            padding: 8px 6px;
            text-align: left;
            font-size: 9pt;
            font-weight: bold;
            border-bottom: 2px solid #000;
        }
        table.items td {
            padding: 6px;
            border-bottom: 1px solid #eee;
            font-size: 9pt;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .variant-info {
            font-size: 8pt;
            color: #666;
        }
        .summary-box {
            background-color: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }
        .summary-box h3 {
            color: #166534;
            font-size: 12pt;
            margin-bottom: 10px;
        }
        .summary-table {
            width: 100%;
        }
        .summary-table td {
            padding: 5px 0;
            font-size: 10pt;
        }
        .summary-total {
            border-top: 2px solid #166534;
            padding-top: 8px;
            margin-top: 5px;
            font-weight: bold;
            font-size: 14pt;
            color: #166534;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête -->
        <div class="header">
            <h1>BON DE COMMANDE</h1>
            <div class="company-info">
                {{ $company['name'] }} | {{ $company['address'] }}<br>
                Tél: {{ $company['phone'] }} | Email: {{ $company['email'] }}
            </div>
        </div>

        <!-- Informations bon -->
        <div style="margin-bottom: 20px;">
            <div style="font-size: 9pt;">
                <strong>Numéro :</strong> {{ $form_number }}<br>
                <strong>Date :</strong> {{ $date }}
            </div>
        </div>

        <!-- Informations vendeur -->
        <div class="vendor-section">
            <h3>🏪 Vendeur</h3>
            <div style="font-size: 9pt;">
                <strong>{{ $vendor['name'] }}</strong><br>
                @if($vendor['email'])
                    Email: {{ $vendor['email'] }}<br>
                @endif
                @if($vendor['address'])
                    Adresse: {{ $vendor['address'] }}
                @endif
            </div>
        </div>

        <!-- Commandes -->
        @foreach($orders as $order)
        <div class="order-block">
            <div class="order-header">
                <h4>📦 Commande {{ $order['order_number'] }}</h4>
                <div class="customer-info">
                    <strong>Client :</strong> {{ $order['customer']['name'] }} | 
                    {{ $order['customer']['email'] }} | 
                    Tél: {{ $order['customer']['phone'] }}<br>
                    <strong>Date :</strong> {{ $order['date'] }}
                </div>
            </div>

            <!-- Articles -->
            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 50%;">Article</th>
                        <th class="text-center" style="width: 15%;">Qté</th>
                        <th class="text-right" style="width: 15%;">P.U.</th>
                        <th class="text-right" style="width: 20%;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order['items'] as $item)
                    <tr>
                        <td>
                            <strong>{{ $item['name'] }}</strong>
                            @if($item['variant'])
                                <div class="variant-info">{{ $item['variant'] }}</div>
                            @endif
                            @if($item['sku'])
                                <div class="variant-info">SKU: {{ $item['sku'] }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ $item['quantity'] }}</td>
                        <td class="text-right">{{ $item['unit_price'] }}</td>
                        <td class="text-right"><strong>{{ $item['total'] }}</strong></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div style="text-align: right; margin-top: 10px; font-size: 11pt;">
                <strong>Total commande :</strong> {{ $order['total'] }}
            </div>
        </div>
        @endforeach

        <!-- Récapitulatif financier -->
        <div class="summary-box">
            <h3>💰 Récapitulatif Financier</h3>
            <table class="summary-table">
                <tr>
                    <td>Montant brut total :</td>
                    <td class="text-right"><strong>{{ $summary['total_gross'] }}</strong></td>
                </tr>
                <tr>
                    <td>Commission plateforme ({{ $summary['platform_fee_rate'] }}%) :</td>
                    <td class="text-right">- {{ $summary['platform_fee'] }}</td>
                </tr>
                <tr class="summary-total">
                    <td>NET À RECEVOIR :</td>
                    <td class="text-right">{{ $summary['net_amount'] }}</td>
                </tr>
            </table>
        </div>

        <!-- Instructions -->
        <div style="margin-top: 20px; padding: 15px; background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
            <h4 style="font-size: 11pt; color: #1e40af; margin-bottom: 8px;">📋 Instructions</h4>
            <div style="font-size: 9pt; color: #1e40af;">
                1. Préparez les articles listés ci-dessus<br>
                2. Connectez-vous à votre espace vendeur<br>
                3. Marquez la/les commande(s) comme "Prête"<br>
                4. Le client sera automatiquement notifié
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <strong>{{ $company['name'] }}</strong><br>
            Bon de commande généré automatiquement le {{ $date }}<br>
            Pour toute question : {{ $company['email'] }}
        </div>
    </div>
</body>
</html>