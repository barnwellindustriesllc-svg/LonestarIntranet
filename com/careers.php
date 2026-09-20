<?php
$pageTitle = "Owner Operator Trucking Opportunities in Texas | Lonestar Roadside";
$currentPage = "careers";
$metaDescription = "Texas-based owner operator trucking opportunities with 24/7 dispatch and consistent industrial freight lanes.";
include __DIR__ . "/includes/header.php";
?>

<section class="section hero-sub hero-careers">
    <div class="container">
        <p class="tagline">CAREERS</p>
        <h1>Drive with a team that has your back.</h1>
        <p class="hero-subtitle">
            Competitive pay, honest communication, and loads that respect your time.
        </p>
    </div>
</section>

<section class="section section-light">
    <div class="container grid-3">
        <div class="card">
            <h3>Reliable Miles</h3>
            <p>Consistent freight and lanes designed for real-world schedules.</p>
        </div>
        <div class="card">
            <h3>Fair Pay</h3>
            <p>Transparent pay structures and on-time settlements.</p>
        </div>
        <div class="card">
            <h3>Driver-First Culture</h3>
            <p>We’ve been in the driver’s seat. We understand what matters.</p>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-title">Current Opportunities</h2>
        <p>
            We’re always interested in experienced drivers and logistics professionals.
            Use the form below to introduce yourself and we’ll reach out when there’s a fit.
        </p>
        <form class="form" method="post" action="/contact-process.php">
            <input type="hidden" name="form_type" value="careers" />
            <div class="form-grid">
                <div class="form-group">
                    <label for="career_name">Full Name</label>
                    <input type="text" id="career_name" name="name" required />
                </div>
                <div class="form-group">
                    <label for="career_email">Email</label>
                    <input type="email" id="career_email" name="email" required />
                </div>
                <div class="form-group">
                    <label for="career_phone">Phone</label>
                    <input type="tel" id="career_phone" name="phone" required />
                </div>
                <div class="form-group">
                    <label for="career_position">Position Interested In</label>
                    <input type="text" id="career_position" name="position" placeholder="Driver, Dispatch, etc." />
                </div>
            </div>
            <div class="form-group">
                <label for="career_experience">Tell us about your experience</label>
                <textarea id="career_experience" name="message" rows="5"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit Application</button>
        </form>
    </div>
</section>

<?php
include __DIR__ . "/includes/footer.php";
?>
