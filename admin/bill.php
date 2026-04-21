<?php
/**
 * 管理员后台 - 账单查询
 * 按月查询，选择年份和月份，展示账户账单摘要
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'common.php';
require_once ROOT_PATH . 'core' . DIRECTORY_SEPARATOR . 'AliyunBss.php';

$admin = getCurrentAdmin();
$db = getDb();
$prefix = $db->getPrefix();

$configs = $db->fetchAll("SELECT * FROM `{$prefix}aliyun_config` WHERE `status` = 1 ORDER BY `is_default` DESC, `id` ASC");

$selectedConfigId = intval($_GET['config_id'] ?? 0);
$selectedYear = intval($_GET['year'] ?? date('Y'));
$selectedMonth = intval($_GET['month'] ?? date('m'));

if ($selectedConfigId <= 0 && !empty($configs)) {
    $selectedConfigId = $configs[0]['id'];
}

$selectedConfig = null;
foreach ($configs as $c) {
    if ($c['id'] == $selectedConfigId) {
        $selectedConfig = $c;
        break;
    }
}

$billingCycle = sprintf('%04d-%02d', $selectedYear, $selectedMonth);

$billSummary = null;
$balanceData = null;
$billItems = [];
$error = '';

if ($selectedConfig) {
    $secret = Helper::decrypt($selectedConfig['access_key_secret']);
    if (empty($secret)) {
        $error = 'AccessKey Secret解密失败，请重新配置';
    } else {
        $bss = new AliyunBss($selectedConfig['access_key_id'], $secret);

        $balanceResult = $bss->queryAccountBalance();
        if ($balanceResult['success']) {
            $balanceData = $balanceResult['data'];
        }

        $billResult = $bss->queryAccountBill($billingCycle, '', 'MONTHLY', '', 1, 50);
        if ($billResult['success']) {
            $billData = $billResult['data'];
            $rawItems = $billData['AccountBillList']['Items']['Item'] ?? [];
            if (isset($rawItems['BillingCycle'])) {
                $rawItems = [$rawItems];
            }
            $billItems = $rawItems;

            $currency = 'CNY';
            $totalPretaxGross = 0;
            $totalInvoiceDiscount = 0;
            $totalDeductedByCashCoupons = 0;
            $totalDeductedByCoupons = 0;
            $totalRoundDownDiscount = 0;
            $totalPretaxAmount = 0;
            $totalPaymentAmount = 0;
            $totalTax = 0;
            $totalAfterTaxAmount = 0;
            $totalPaidAmount = 0;
            $totalOutstandingAmount = 0;

            foreach ($rawItems as $item) {
                $currency = $item['Currency'] ?? 'CNY';
                $totalPretaxGross += floatval($item['PretaxGrossAmount'] ?? 0);
                $totalInvoiceDiscount += floatval($item['InvoiceDiscount'] ?? 0);
                $totalDeductedByCashCoupons += floatval($item['DeductedByCashCoupons'] ?? 0);
                $totalDeductedByCoupons += floatval($item['DeductedByCoupons'] ?? 0);
                $totalRoundDownDiscount += floatval($item['RoundDownDiscount'] ?? 0);
                $totalPretaxAmount += floatval($item['PretaxAmount'] ?? 0);
                $totalPaymentAmount += floatval($item['PaymentAmount'] ?? 0);
                $totalTax += floatval($item['Tax'] ?? 0);
                $totalAfterTaxAmount += floatval($item['AfterTaxAmount'] ?? 0);
                $totalPaidAmount += floatval($item['PaidAmount'] ?? 0);
                $totalOutstandingAmount += floatval($item['OutstandingAmount'] ?? 0);
            }

            $billSummary = [
                'currency' => $currency,
                'pretax_gross' => $totalPretaxGross,
                'invoice_discount' => $totalInvoiceDiscount,
                'deducted_by_cash_coupons' => $totalDeductedByCashCoupons,
                'deducted_by_coupons' => $totalDeductedByCoupons,
                'round_down_discount' => $totalRoundDownDiscount,
                'pretax_amount' => $totalPretaxAmount,
                'payment_amount' => $totalPaymentAmount,
                'tax' => $totalTax,
                'after_tax_amount' => $totalAfterTaxAmount,
                'paid_amount' => $totalPaidAmount,
                'outstanding_amount' => $totalOutstandingAmount
            ];
        } else {
            $error = $billResult['error'] ?? '查询账单失败';
        }
    }
}

$availableAmount = 0;
$availableCash = 0;
$creditAmount = 0;
$outstandingBalance = 0;

if ($balanceData) {
    $availableAmount = floatval($balanceData['AvailableAmount'] ?? 0);
    $availableCash = floatval($balanceData['AvailableCashAmount'] ?? 0);
    $creditAmount = floatval($balanceData['CreditAmount'] ?? 0);
    $outstandingBalance = floatval($balanceData['OutstandingAmount'] ?? 0);
}

$currentYear = intval(date('Y'));
$yearOptions = range($currentYear - 3, $currentYear);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>账单查询 - 阿里云流量查询系统</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .bill-filter {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .bill-filter .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .bill-filter label {
            font-size: 12px;
            color: #94a3b8;
            font-weight: 600;
        }
        .bill-filter select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #e2e8f0;
            font-size: 13px;
            min-width: 100px;
            cursor: pointer;
        }
        .bill-filter select:focus {
            outline: none;
            border-color: #667eea;
        }
        .bill-btn {
            padding: 8px 24px;
            border-radius: 8px;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .bill-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .balance-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .balance-card {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .balance-card .label {
            font-size: 12px;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .balance-card .value {
            font-size: 24px;
            font-weight: 700;
        }
        .balance-card .value.green { color: #10b981; }
        .balance-card .value.blue { color: #3b82f6; }
        .balance-card .value.orange { color: #f59e0b; }
        .balance-card .value.red { color: #ef4444; }
        .balance-card .value.purple { color: #8b5cf6; }
        .bill-summary-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 16px;
            padding: 32px;
            border: 1px solid rgba(102, 126, 234, 0.2);
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .bill-summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2, #f093fb);
        }
        .bill-summary-title {
            font-size: 20px;
            font-weight: 700;
            color: #e2e8f0;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .bill-summary-title svg {
            width: 24px;
            height: 24px;
            color: #667eea;
        }
        .bill-summary-grid {
            display: flex;
            flex-direction: column;
        }
        .bill-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            transition: background 0.15s;
        }
        .bill-row:hover {
            background: rgba(102, 126, 234, 0.04);
        }
        .bill-row:last-child {
            border-bottom: none;
        }
        .bill-row .row-label {
            font-size: 14px;
            color: #94a3b8;
            font-weight: 500;
        }
        .bill-row .row-value {
            font-size: 14px;
            font-weight: 700;
            color: #e2e8f0;
            font-family: 'Courier New', monospace;
        }
        .bill-row .row-value.highlight {
            color: #667eea;
            font-size: 16px;
        }
        .bill-row .row-value.green { color: #10b981; }
        .bill-row .row-value.red { color: #ef4444; }
        .bill-row .row-value.orange { color: #f59e0b; }
        .bill-row .row-value.dim { color: #64748b; }
        .bill-row.separator {
            border-bottom: 2px solid rgba(102, 126, 234, 0.15);
            margin-top: 4px;
        }
        .bill-detail-section {
            margin-top: 24px;
        }
        .bill-detail-title {
            font-size: 16px;
            font-weight: 700;
            color: #e2e8f0;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .bill-detail-title svg {
            width: 20px;
            height: 20px;
            color: #667eea;
        }
        .bill-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .bill-table th {
            background: #1e293b;
            color: #94a3b8;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #334155;
            white-space: nowrap;
        }
        .bill-table td {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            color: #e2e8f0;
        }
        .bill-table tr:hover td {
            background: rgba(102, 126, 234, 0.05);
        }
        .amount { font-weight: 600; }
        .amount.positive { color: #10b981; }
        .amount.negative { color: #ef4444; }
        .amount.zero { color: #64748b; }
        .product-tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .product-tag.prepaid { background: rgba(102, 126, 234, 0.2); color: #a5b4fc; }
        .product-tag.postpaid { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #64748b;
            font-size: 14px;
        }
        .config-name-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            background: rgba(102, 126, 234, 0.15);
            color: #a5b4fc;
            font-size: 13px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'bill'; include __DIR__ . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-left">
                    <h1>账单查询</h1>
                </div>
                <div class="topbar-right">
                    <span class="admin-name">欢迎，<?php echo htmlspecialchars($admin['username']); ?></span>
                </div>
            </header>
            
            <div class="content">
                <?php if (!empty($error)): ?>
                <div class="error-msg">
                    <strong>错误：</strong><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <form method="GET" action="" id="billForm">
                    <div class="bill-filter">
                        <div class="form-group">
                            <label>选择配置</label>
                            <select name="config_id" onchange="this.form.submit()">
                                <?php foreach ($configs as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $selectedConfigId ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['access_key_id']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>年份</label>
                            <select name="year" onchange="this.form.submit()">
                                <?php foreach ($yearOptions as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>月份</label>
                            <select name="month" onchange="this.form.submit()">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $selectedMonth ? 'selected' : ''; ?>><?php echo sprintf('%02d', $m); ?>月</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="bill-btn">查询</button>
                        </div>
                    </div>
                </form>

                <?php if ($balanceData): ?>
                <div class="balance-cards">
                    <div class="balance-card">
                        <div class="label">可用余额</div>
                        <div class="value green"><?php echo AliyunBss::formatAmount($availableAmount, $balanceData['Currency'] ?? 'CNY'); ?></div>
                    </div>
                    <div class="balance-card">
                        <div class="label">可用现金</div>
                        <div class="value blue"><?php echo AliyunBss::formatAmount($availableCash, $balanceData['Currency'] ?? 'CNY'); ?></div>
                    </div>
                    <div class="balance-card">
                        <div class="label">信用额度</div>
                        <div class="value purple"><?php echo AliyunBss::formatAmount($creditAmount, $balanceData['Currency'] ?? 'CNY'); ?></div>
                    </div>
                    <div class="balance-card">
                        <div class="label">欠费金额</div>
                        <div class="value <?php echo $outstandingBalance > 0 ? 'red' : 'green'; ?>"><?php echo AliyunBss::formatAmount($outstandingBalance, $balanceData['Currency'] ?? 'CNY'); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($billSummary): ?>
                <div class="bill-summary-card">
                    <div class="bill-summary-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                        账户账单
                    </div>
                    <div class="bill-summary-grid">
                        <div class="bill-row">
                            <span class="row-label">配置名称</span>
                            <span class="row-value"><?php echo htmlspecialchars($selectedConfig['name'] ?? '-'); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">账单月份</span>
                            <span class="row-value highlight"><?php echo htmlspecialchars($billingCycle); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">目录总价</span>
                            <span class="row-value"><?php echo AliyunBss::formatAmount($billSummary['pretax_gross'], $billSummary['currency'], 6); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">优惠金额</span>
                            <span class="row-value <?php echo $billSummary['invoice_discount'] > 0 ? 'green' : 'dim'; ?>"><?php echo AliyunBss::formatAmount($billSummary['invoice_discount'], $billSummary['currency'], 6); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">优惠券抵扣金额</span>
                            <span class="row-value <?php echo ($billSummary['deducted_by_cash_coupons'] + $billSummary['deducted_by_coupons']) > 0 ? 'green' : 'dim'; ?>"><?php echo AliyunBss::formatAmount($billSummary['deducted_by_cash_coupons'] + $billSummary['deducted_by_coupons'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">抹零金额</span>
                            <span class="row-value <?php echo $billSummary['round_down_discount'] > 0 ? 'orange' : 'dim'; ?>"><?php echo AliyunBss::formatAmount($billSummary['round_down_discount'], $billSummary['currency'], 6); ?></span>
                        </div>
                        <div class="bill-row separator">
                            <span class="row-label">应付金额(税前)</span>
                            <span class="row-value highlight"><?php echo AliyunBss::formatAmount($billSummary['pretax_amount'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">应付金额(支付币种)</span>
                            <span class="row-value"><?php echo AliyunBss::formatAmount($billSummary['payment_amount'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">税</span>
                            <span class="row-value <?php echo $billSummary['tax'] > 0 ? 'orange' : 'dim'; ?>"><?php echo AliyunBss::formatAmount($billSummary['tax'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">税后金额</span>
                            <span class="row-value"><?php echo AliyunBss::formatAmount($billSummary['after_tax_amount'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row separator">
                            <span class="row-label">应付金额(税后)</span>
                            <span class="row-value highlight"><?php echo AliyunBss::formatAmount($billSummary['after_tax_amount'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">已还款金额</span>
                            <span class="row-value <?php echo $billSummary['paid_amount'] > 0 ? 'green' : 'dim'; ?>"><?php echo AliyunBss::formatAmount($billSummary['paid_amount'], $billSummary['currency']); ?></span>
                        </div>
                        <div class="bill-row">
                            <span class="row-label">待还款金额</span>
                            <span class="row-value <?php echo $billSummary['outstanding_amount'] > 0 ? 'red' : 'green'; ?>"><?php echo AliyunBss::formatAmount($billSummary['outstanding_amount'], $billSummary['currency']); ?></span>
                        </div>
                    </div>
                </div>
                <?php elseif (empty($error)): ?>
                <div class="no-data">暂无账单数据，请选择配置和月份后查询</div>
                <?php endif; ?>

                <?php if (!empty($billItems)): ?>
                <div class="bill-detail-section">
                    <div class="bill-detail-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6" x2="21" y2="6"></line>
                            <line x1="8" y1="12" x2="21" y2="12"></line>
                            <line x1="8" y1="18" x2="21" y2="18"></line>
                            <line x1="3" y1="6" x2="3.01" y2="6"></line>
                            <line x1="3" y1="12" x2="3.01" y2="12"></line>
                            <line x1="3" y1="18" x2="3.01" y2="18"></line>
                        </svg>
                        产品账单明细
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="bill-table">
                            <thead>
                                <tr>
                                    <th>产品名称</th>
                                    <th>产品代码</th>
                                    <th>订阅类型</th>
                                    <th>目录总价</th>
                                    <th>优惠金额</th>
                                    <th>应付金额</th>
                                    <th>现金支付</th>
                                    <th>待还款金额</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($billItems as $item):
                                    $currency = $item['Currency'] ?? 'CNY';
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['ProductName'] ?? $item['ProductCode'] ?? '-'); ?></strong></td>
                                    <td><code style="font-size:11px;color:#94a3b8;"><?php echo htmlspecialchars($item['ProductCode'] ?? '-'); ?></code></td>
                                    <td>
                                        <?php
                                        $subType = $item['SubscriptionType'] ?? '';
                                        echo '<span class="product-tag ' . ($subType === 'Subscription' || $subType === 'PrePaid' ? 'prepaid' : 'postpaid') . '">' . AliyunBss::formatSubscriptionType($subType) . '</span>';
                                        ?>
                                    </td>
                                    <td class="amount <?php echo floatval($item['PretaxGrossAmount'] ?? 0) > 0 ? 'positive' : 'zero'; ?>">
                                        <?php echo AliyunBss::formatAmount($item['PretaxGrossAmount'] ?? 0, $currency); ?>
                                    </td>
                                    <td class="amount <?php echo floatval($item['InvoiceDiscount'] ?? 0) > 0 ? 'positive' : 'zero'; ?>">
                                        <?php echo AliyunBss::formatAmount($item['InvoiceDiscount'] ?? 0, $currency); ?>
                                    </td>
                                    <td class="amount <?php echo floatval($item['PretaxAmount'] ?? 0) > 0 ? 'positive' : (floatval($item['PretaxAmount'] ?? 0) < 0 ? 'negative' : 'zero'); ?>">
                                        <?php echo AliyunBss::formatAmount($item['PretaxAmount'] ?? 0, $currency); ?>
                                    </td>
                                    <td class="amount <?php echo floatval($item['PaymentAmount'] ?? 0) > 0 ? 'positive' : 'zero'; ?>">
                                        <?php echo AliyunBss::formatAmount($item['PaymentAmount'] ?? 0, $currency); ?>
                                    </td>
                                    <td class="amount <?php echo floatval($item['OutstandingAmount'] ?? 0) > 0 ? 'negative' : 'zero'; ?>">
                                        <?php echo AliyunBss::formatAmount($item['OutstandingAmount'] ?? 0, $currency); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
