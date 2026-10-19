<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="SBH">
<link rel="icon" type="image/png" sizes="32x32" href="capt-putih.png">
    <title>Netflix Notif</title>
    <!-- Custom fonts for this template -->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <!-- Custom styles for this template -->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <style>
/* Responsive fix */
@media (max-width: 768px) {

    /* Sidebar lebih kecil */
    .sidebar {
        width: 70px !important;
    }

    .sidebar .nav-item .nav-link span {
        display: none;
    }

    .sidebar .sidebar-brand-text {
        display: none;
    }

    /* Topbar spacing */
    .topbar {
        padding: 0.5rem;
    }

    /* Card padding lebih kecil */
    .card-body {
        padding: 1rem;
    }

    /* Button full width di HP */
    .btn {
        width: 100%;
        margin-bottom: 10px;
    }

    /* Email content biar tidak keluar layar */
    .card-body div {
        overflow-x: auto;
        word-break: break-word;
    }

    /* Gambar dalam email responsive */
    .card-body img {
        max-width: 100%;
        height: auto;
    }

    /* Font lebih kecil */
    h6 {
        font-size: 14px;
    }

    small {
        font-size: 12px;
    }
}

.email-content {
    max-width: 100%;
    overflow-x: auto;
}

.email-content table {
    width: 100% !important;
}
</style>
</head>
<body id="page-top">
    <!-- Page Wrapper -->
    <div id="wrapper">
        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <!-- Sidebar - Brand -->
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
                <div class="sidebar-brand-icon rotate-n-15">
                    <i class="fas fa-user-secret"></i>
                </div>
                <div class="sidebar-brand-text mx-3">CAPTSHOP NETFLIX</div>
            </a>
            <!-- Divider -->
            <hr class="sidebar-divider my-0">
            <!-- Nav Item - Dashboard -->
            <li class="nav-item">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <hr class="sidebar-divider">
            <!-- Sidebar Toggler (Sidebar) -->
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle"></button>
            </div>
        </ul>
        <!-- End of Sidebar -->

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <!-- Sidebar Toggle (Topbar) -->
                    <form class="form-inline">
                        <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                            <i class="fa fa-bars"></i>
                        </button>
                    </form>
                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">
                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small">CaptShop</span>
                                <img class="img-profile rounded-circle" src="capt.png">
                            </a>
                        </li>
                    </ul>
                </nav>
                <!-- End of Topbar -->

                <!-- Begin Page Content -->
                <div class="container-fluid">
                    <?php
if (isset($_POST['email_id']) || isset($_POST['email']) || isset($_POST['subject'])) {
    $mailIdhash = $_POST['email_id'];
    $emailnyahash = $_POST['email'];
    $subjecthash = $_POST['subject'];
    
    // Gmail IMAP settings
    $configData = file_get_contents('config.json');
    $config = json_decode($configData, true);

    $mailId = base64_decode($mailIdhash);
    $emailnya = base64_decode($emailnyahash);
    $subjectnya = base64_decode($subjecthash);

    $server = $config['imap_server'];
    $username = $config['email_username'];
    $password = $config['email_password'];

    // Connect to the IMAP server
    $mailbox = imap_open($server, $username, $password);
    if (!$mailbox) {
        echo '<div class="email-card">Unable to connect. Error: ' . imap_last_error() . '</div>';
    } else {
        $header = imap_headerinfo($mailbox, $mailId);
        $kepada = isset($header->toaddress) ? htmlspecialchars($header->toaddress) : '';
        $subject = isset($header->subject) ? htmlspecialchars($header->subject) : '';
        $from = isset($header->fromaddress) ? htmlspecialchars($header->fromaddress) : '';
        $date = isset($header->date) ? htmlspecialchars($header->date) : '';

        if (strcasecmp(trim($emailnya), trim($kepada)) === 0) {
            
            if (strcasecmp(trim($subjectnya), trim($subject)) === 0) {
                $structure = imap_fetchstructure($mailbox, $mailId);

                function getHtmlBody($mailbox, $mailId, $structure, $partNumber = '') {
                    $htmlBody = '';
                    if ($structure->type == 1) { // multipart
                        foreach ($structure->parts as $index => $subStruct) {
                            $subPartNumber = $partNumber ? $partNumber . '.' . ($index + 1) : ($index + 1);
                            $htmlBody .= getHtmlBody($mailbox, $mailId, $subStruct, $subPartNumber);
                        }
                    } else {
                        if ($structure->subtype == 'HTML') {
                            $htmlBody = imap_fetchbody($mailbox, $mailId, $partNumber, FT_PEEK);
                            if ($structure->encoding == 4) {
                                $htmlBody = quoted_printable_decode($htmlBody);
                            } elseif ($structure->encoding == 3) {
                                $htmlBody = base64_decode($htmlBody);
                            }
                        }
                    }

                    return $htmlBody;
                }

                $htmlMessageBody = getHtmlBody($mailbox, $mailId, $structure);
                
                $unwantedText = [
                    "Jaga keamanan akunmu: Jika kamu tidak tahu siapa ini, sebaiknya segera keluar dari semua perangkat yang tidak kamu kenal. Kamu juga bisa mengubah sandimu.",
                    "Kami siap membantu",
                    "Kunjungi Pusat Bantuan untuk informasi lebih lanjut.",
                    "Tim Netflix"
                ];
                foreach ($unwantedText as $text) {
                    $htmlMessageBody = preg_replace('/\s*' . preg_quote($text, '/') . '\s*/', '', $htmlMessageBody);
                }
                
                echo '<div class="card-header py-3">';
                echo "<h6 class='m-0 font-weight-bold text-primary'>$subject</h6>";
                echo "<small>From: $from</small><br>";
                echo "<small>Date: $date</small>";
                echo '</div>';
                echo '<div class="card-body">';
                echo '<a href="index.php"><button class="btn btn-danger btn-block" type="button"><i class="fa fa-arrow-left"></i> Kembali</button></a><br><br>';

                if (!empty($htmlMessageBody)) {
                    echo "<div class='email-content'>$htmlMessageBody</div>";
                } else {
                    echo '<div class="alert alert-warning">This email does not contain any HTML content.</div>';
                }

                echo '</div>';
            } else {
                echo '<div class="alert alert-danger">Subject does not match the provided subject.</div>';
            }
        } else {
            echo '<div class="alert alert-danger">Email tidak sesuai dengan kepada ID ini.</div>';
        }

        imap_close($mailbox);
    }
}
?>



                </div>
            </div>
            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>CAPTSHOP &copy; 2026</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (window.innerWidth < 768) {
        document.body.classList.add("sidebar-toggled");
        document.querySelector(".sidebar").classList.add("toggled");
    }
});
</script>
</body>
</html>
