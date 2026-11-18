<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facture {{ $invoice_number }}</title>
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
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 24pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .company-info {
            font-size: 9pt;
            color: #666;
        }
        
        .invoice-info {
            margin-bottom: 20px;
        }
        
        .invoice-info h2 {
            font-size: 18pt;
            margin-bottom: 5px;
        }
        
        .parties {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .party {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 15px;
        }
        
        .party h3 {
            font-size: 11pt;
            margin-bottom: 8px;
            text-transform: uppercase;
            color: #000;
        }
        
        .party-content {
            font-size: 9pt;
            line-height: 1.5;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        table.items {
            border: 1px solid #ddd;
        }
        
        table.items th {
            background-color: #f5f5f5;
            padding: 10px 8px;
            text-align: left;
            font-size: 9pt;
            font-weight: bold;
            border-bottom: 2px solid #000;
        }
        
        table.items td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            font-size: 9pt;
        }
        
        table.items tr:last-child td {
            border-bottom: none;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .product-name {
            font-weight: bold;
        }
        
        .variant-info {
            font-size: 8pt;
            color: #666;
            margin-top: 2px;
        }
        
        .totals-table {
            width: 50%;
            margin-left: auto;
            margin-top: 10px;
        }
        
        .totals-table td {
            padding: 5px 8px;
            font-size: 10pt;
        }
        
        .totals-table .total-row td {
            font-weight: bold;
            font-size: 12pt;
            border-top: 2px solid #000;
            padding-top: 8px;
        }
        
        .promo-badge {
            background-color: #1CCA5B;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 8pt;
            display: inline-block;
            margin-top: 5px;
        }
        
        .payment-info {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .payment-info h3 {
            font-size: 11pt;
            margin-bottom: 8px;
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
            <h1>{{ $company['name'] }}</h1>
            <div class="company-info">
                {{ $company['address'] }} | Tél: {{ $company['phone'] }}<br>
                Email: {{ $company['email'] }} | {{ $company['website'] }}
            </div>
        </div>

        <!-- Informations facture -->
        <div class="invoice-info">
            <h2>FACTURE N° {{ $invoice_number }}</h2>
            <div style="font-size: 9pt;">
                Date: {{ $date }}<br>
                Commande: {{ $order['number'] }}
            </div>
        </div>

        <!-- Parties (Client & Vendeur) -->
        <div class="parties">
            <div class="party">
                <h3>Facturé à:</h3>
                <div class="party-content">
                    <strong>{{ $customer['name'] }}</strong><br>
                    {{ $customer['email'] }}
                </div>
            </div>
            
            <div class="party">
                <h3>Vendu par:</h3>
                <div class="party-content">
                    <strong>{{ $vendor['name'] }}</strong><br>
                    @if($vendor['email'])
                        {{ $vendor['email'] }}<br>
                    @endif
                    @if($vendor['address'])
                        {{ $vendor['address'] }}
                    @endif
                </div>
            </div>
        </div>

        <!-- Informations commande -->
        <div style="margin-bottom: 20px; padding: 10px; background-color: #f9f9f9; border-left: 3px solid #000;">
            <div style="font-size: 9pt;">
                <strong>Commande:</strong> {{ $order['number'] }}<br>
                <strong>Date:</strong> {{ $order['date'] }}<br>
                <strong>Statut:</strong> {{ $payment['status'] }}
            </div>
        </div>

        <!-- Articles -->
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 50%;">Article</th>
                    <th class="text-center" style="width: 15%;">Quantité</th>
                    <th class="text-right" style="width: 15%;">Prix unitaire</th>
                    <th class="text-right" style="width: 20%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item['name'] }}</div>
                        @if($item['variant'])
                            <div class="variant-info">{{ $item['variant'] }}</div>
                        @endif
                        @if($item['sku'])
                            <div class="variant-info">Réf: {{ $item['sku'] }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ $item['quantity'] }}</td>
                    <td class="text-right">{{ $item['unit_price'] }}</td>
                    <td class="text-right">{{ $item['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totaux -->
        <table class="totals-table">
            <tr>
                <td>Sous-total</td>
                <td class="text-right">{{ $totals['subtotal'] }}</td>
            </tr>
            @if($totals['discount'])
            <tr style="color: #1CCA5B;">
                <td>
                    Remise
                    @if($promo_code)
                        <span class="promo-badge">{{ $promo_code['code'] }}</span>
                    @endif
                </td>
                <td class="text-right">- {{ $totals['discount'] }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td>TOTAL</td>
                <td class="text-right">{{ $totals['total'] }}</td>
            </tr>
        </table>

        <!-- Informations paiement -->
        <div class="payment-info">
            <h3>✓ Paiement confirmé</h3>
            <div style="font-size: 9pt;">
                Cette facture a été réglée le {{ $payment['date'] }} via {{ $payment['method'] }}
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <strong>{{ $company['name'] }}</strong> - {{ $company['address'] }}<br>
            Cette facture a été générée le {{ $date }}<br>
            Pour toute question, contactez-nous à {{ $company['email'] }}
        </div>
    </div>
</body>
</html>