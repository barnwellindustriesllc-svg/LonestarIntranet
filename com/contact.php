<?php
$pageTitle = "Contact Lonestar Roadside | Texas Industrial Logistics";
$currentPage = "contact";
include __DIR__ . "/includes/header.php";
?>

<section class="section hero-sub hero-contact">
    <div class="container">
        <p class="tagline">CONTACT</p>
        <h1>Get in touch with dispatch.</h1>
        <p class="hero-subtitle">
            Need a quote or have a logistics question? Reach out and we’ll respond quickly.
        </p>
    </div>
</section>

<section class="section section-light">
    <div class="container grid-2">
        <div>
            <h2 class="section-title">Contact Details</h2>
            <p>
                <strong>Dispatch Phone:</strong> <a href="tel:14322013005">1-432-201-3005</a><br />
                <strong>Email:</strong> <a href="mailto:info@lonestarroadsidetx.com">info@lonestarroadsidetx.com</a><br />
                <strong>Hours:</strong> 24/7 for urgent logistics requests.
            </p>
            <p>
                For quotes, share as much detail as you can about the load, equipment needed, and timing.
            </p>
        </div>

        <div>
            <h2 class="section-title">Request a Quote</h2>
            <form class="form" method="post" action="contact-process.php">
                <input type="hidden" name="form_type" value="quote" />
                <div class="form-grid">
                    <div class="form-group">
                        <label for="quote_name">Name</label>
                        <input type="text" id="quote_name" name="name" required />
                    </div>
                    <div class="form-group">
                        <label for="quote_company">Company</label>
                        <input type="text" id="quote_company" name="company" />
                    </div>
                    <div class="form-group">
                        <label for="quote_email">Email</label>
                        <input type="email" id="quote_email" name="email" required />
                    </div>
                    <div class="form-group">
                        <label for="quote_phone">Phone</label>
                        <input type="tel" id="quote_phone" name="phone" required />
                    </div>
                </div>
                <div class="form-group">
                    <label for="quote_type">Type of Request</label>
                    <select id="quote_type" name="request_type">
                        <option value="Quote">Quote</option>
                        <option value="Expedited">Expedited Shipment</option>
                        <option value="General">General Question</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quote_details">Load / Request Details</label>
                    <textarea id="quote_details" name="message" rows="6" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Send Request</button>
            </form>
        </div>
    </div>
</section>

<?php
include "includes/footer.php";
?>
