<?php
session_start();
require_once 'config/database.php';

$certId = $_GET['id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;

if (!$certId || !$userId) {
    die('Invalid access');
}

// Fetch certificate
$stmt = $pdo->prepare("
    SELECT c.*, f.name as farm_name 
    FROM certificates c JOIN farms f ON c.farm_id = f.id 
    WHERE c.id = ? AND f.ownerId = ? AND c.status = 'Active'
");
$stmt->execute([$certId, $userId]);
$cert = $stmt->fetch();

if (!$cert) {
    die('Certificate not found');
}

// Generate PDF using HTML (or use TCPDF/FPDF)
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DA Camarines Sur Certificate</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Paste ALL e_certificate.css styles here */
        body { font-family: "Georgia", serif; margin: 0; padding: 0; background: white; }
        .full-cert-view { max-width: none; box-shadow: none; }
        /* ... all your CSS ... */
    </style>
</head>
<body>
    <!-- Full certificate HTML here -->
    <div class="full-cert-view">
        <!-- Copy the certificate modal content -->
    </div>
</body>
</html>';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="DA-CS-Certificate-' . $cert['certificate_no'] . '.pdf"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// For now, serve HTML - replace with PDF library later
echo $html;
?>