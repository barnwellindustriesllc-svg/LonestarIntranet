<?php
    if (!isset($pageTitle)) {
        $pageTitle = "Lonestar Roadside LLC";
    }
    if (!isset($currentPage)) {
        $currentPage = "";
    }
?>
    
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />

    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <meta name="description" content="<?php echo htmlspecialchars($metaDescription); ?>">

    <!-- Robots directive -->
    <meta name="robots" content="index, follow">

    <!-- Google Search Console verification -->
    <meta name="google-site-verification" content="13r_MQhGWmSJiHcdBasfFK9LSQGtuLPwtfl8mkjf7dA" />

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-YXK6ERL67H"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-YXK6ERL67H');
    </script>
    
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <?php
        if (!isset($metaDescription)) {
            $metaDescription = "Industrial logistics and hauling services across South and West Texas for oil & gas, construction, and manufacturing operations.";
        }
    ?> 
    
    <!-- Organization / TruckingCompany Schema -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "TruckingCompany",
            "name": "Lonestar Roadside LLC",
            "url": "https://lonestarroadsidetx.com",
            "areaServed": "Texas",
            "knowsAbout": [
            "Oil and Gas Logistics",
            "Construction Materials Hauling",
            "Manufacturing and Distribution Logistics"
            ]
        }
    </script>
    
    <link rel="icon" href="/favicon.ico" type="image/x-icon" />
    <link rel="stylesheet" href="assets/css/styles.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet" />
</head>
<body>
<header class="site-header">
    <div class="container nav-container">
        <a href="index.php" class="brand">
            <!-- Swap this with an <img> if you have the logo asset -->
            <div class="brand-mark">
                <img src="assets/images/logo.png" />
                <!--<span class="star-icon">★</span>-->
            </div>
            <div class="brand-text">
                <span class="brand-name">LONESTAR ROADSIDE LLC</span>
                <span class="brand-tagline">Freight Logistics Partner</span>
            </div>
        </a>

        <button class="nav-toggle" aria-label="Toggle navigation">
            <span></span><span></span><span></span>
        </button>

        <nav class="main-nav">
            <ul>
                <li><a href="index.php" class="<?php echo $currentPage === 'home' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="about.php" class="<?php echo $currentPage === 'about' ? 'active' : ''; ?>">About</a></li>
                <li><a href="services.php" class="<?php echo $currentPage === 'services' ? 'active' : ''; ?>">Services</a></li>
                <li><a href="careers.php" class="<?php echo $currentPage === 'careers' ? 'active' : ''; ?>">Careers</a></li>
                <li><a href="contact.php" class="<?php echo $currentPage === 'contact' ? 'active' : ''; ?>">Contact</a></li>
            </ul>
            <a href="contact.php" class="btn btn-primary nav-cta">Get a Quote</a>
        </nav>
    </div>
</header>

<main>
