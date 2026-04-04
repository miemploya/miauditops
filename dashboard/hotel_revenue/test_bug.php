<?php
require_once '../../includes/functions.php';
require_login();
require_subscription('hotel_revenue');
?>
<!DOCTYPE html>
<html>
<head>
    <script src="jspdf.min.js"></script>
    <script src="jspdf.plugin.autotable.min.js"></script>
</head>
<body>
    <input type="text" id="reconCash" value="0">
    <input type="text" id="reconPOS" value="0">
    <input type="text" id="reconTransfer" value="0">
    <div id="otTotalHours">0h</div>
    <div id="riskMinorCount">1</div>
    <div id="riskMinorAmt">N1000</div>
    <div id="riskWarningCount">2</div>
    <div id="riskWarningAmt">N2000</div>
    <div id="riskCriticalCount">3</div>
    <div id="riskCriticalAmt">N3000</div>
    
    <script src="overtime.js"></script>
    <script>
        auditData = [{checkIn: new Date(), dailyRate: 10000, roomType: 'Standard'}];
        overtimeData = [{
            roomType: 'Standard', checkIn: new Date(), expectedOut: new Date(), actualOut: new Date(), diffHours: 5, dailyRate: 10000, isRectified: false, overtimeCharge: 2000
        }];
        doubleSalesData = [];
        reconAdjustments = [];
        console.log('Running exportOvertimePDF...');
        try {
            exportOvertimePDF();
            console.log('SUCCESS');
        } catch(e) {
            console.error('ERROR:', e);
        }
    </script>
</body>
</html>

