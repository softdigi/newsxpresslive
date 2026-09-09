<?php
/**
 * Agency Self-Registration Page
 * NewsXpressLive
 * 
 * Multi-step form for news agencies to join the platform
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$submitted = false;
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $agencyName    = trim(strip_tags($_POST['agency_name'] ?? ''));
    $agencyType    = in_array($_POST['agency_type'] ?? '', ['national', 'international', 'local', 'digital']) 
                     ? $_POST['agency_type'] : 'local';
    $country       = trim(strip_tags($_POST['country'] ?? ''));
    $website       = filter_var($_POST['website'] ?? '', FILTER_SANITIZE_URL);
    $contactName   = trim(strip_tags($_POST['contact_name'] ?? ''));
    $contactEmail  = filter_var($_POST['contact_email'] ?? '', FILTER_SANITIZE_EMAIL);
    $contactPhone  = trim(strip_tags($_POST['contact_phone'] ?? ''));
    $designation   = trim(strip_tags($_POST['designation'] ?? ''));
    $coverageAreas = is_array($_POST['coverage_areas'] ?? null) ? implode(',', $_POST['coverage_areas']) : '';
    $description   = trim(strip_tags($_POST['description'] ?? ''));
    
    // Validate required fields
    if (empty($agencyName) || empty($contactName) || empty($contactEmail)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO agency_applications 
                 (agency_name, agency_type, country, website, contact_name, contact_email, 
                  contact_phone, designation, coverage_areas, description, status, applied_at)
                 VALUES (:name, :type, :country, :website, :cname, :cemail, :cphone, :desig, :coverage, :desc, :status, NOW())'
            );
            $stmt->execute([
                ':name'     => $agencyName,
                ':type'     => $agencyType,
                ':country'  => $country,
                ':website'  => $website,
                ':cname'    => $contactName,
                ':cemail'   => $contactEmail,
                ':cphone'   => $contactPhone,
                ':desig'    => $designation,
                ':coverage' => $coverageAreas,
                ':desc'     => $description,
                ':status'   => 'pending'
            ]);
            
            $submitted = true;
            
        } catch (PDOException $e) {
            // Table might not exist - try to create it
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS agency_applications (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            agency_name VARCHAR(255) NOT NULL,
                            agency_type ENUM('national','international','local','digital') NOT NULL,
                            country VARCHAR(100),
                            website VARCHAR(255),
                            contact_name VARCHAR(255),
                            contact_email VARCHAR(255),
                            contact_phone VARCHAR(50),
                            designation VARCHAR(100),
                            coverage_areas TEXT,
                            description TEXT,
                            status ENUM('pending','approved','rejected') DEFAULT 'pending',
                            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_status (status)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    
                    // Retry insert
                    $stmt = $pdo->prepare(
                        'INSERT INTO agency_applications 
                         (agency_name, agency_type, country, website, contact_name, contact_email, 
                          contact_phone, designation, coverage_areas, description, status, applied_at)
                         VALUES (:name, :type, :country, :website, :cname, :cemail, :cphone, :desig, :coverage, :desc, :status, NOW())'
                    );
                    $stmt->execute([
                        ':name'     => $agencyName,
                        ':type'     => $agencyType,
                        ':country'  => $country,
                        ':website'  => $website,
                        ':cname'    => $contactName,
                        ':cemail'   => $contactEmail,
                        ':cphone'   => $contactPhone,
                        ':desig'    => $designation,
                        ':coverage' => $coverageAreas,
                        ':desc'     => $description,
                        ':status'   => 'pending'
                    ]);
                    
                    $submitted = true;
                    
                } catch (PDOException $e2) {
                    error_log('Agency registration error: ' . $e2->getMessage());
                    $error = 'An error occurred. Please try again later.';
                }
            } else {
                error_log('Agency registration error: ' . $e->getMessage());
                $error = 'An error occurred. Please try again later.';
            }
        }
    }
}

$seoMeta = [
    'title'       => 'Join as News Agency',
    'description' => 'Partner with ' . SITE_NAME . '. Register your news agency and reach millions of readers.',
    'url'         => SITE_URL . '/agency/register.php',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container page-body">
<div class="layout-main" style="max-width: 100%;">

    <?php if ($submitted): ?>
    <div class="agency-register">
        <div class="form-success">
            <div class="form-success__icon">🎉</div>
            <h2 class="form-success__title">Application Submitted!</h2>
            <p class="form-success__message">
                Thank you for your interest in joining NewsXpressLive. 
                Your application is under review. We'll contact you within 24 hours at the email provided.
            </p>
            <a href="<?= SITE_URL ?>/" class="btn btn--red" style="margin-top: 1.5rem;">Back to Home</a>
        </div>
    </div>
    
    <?php else: ?>
    
    <div class="agency-register">
        <h1 class="agency-register__title">📰 Join NewsXpressLive</h1>
        <p class="agency-register__subtitle">
            Partner with India's fastest-growing news platform. Reach millions of readers and expand your audience.
        </p>
        
        <?php if ($error): ?>
        <div style="background: rgba(244, 67, 54, 0.1); border: 1px solid #f44336; padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; color: #f44336;">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
        
        <!-- Progress Steps -->
        <div class="progress-steps">
            <div class="progress-step active">
                <div class="progress-step__circle">1</div>
                <span class="progress-step__label">Agency Info</span>
            </div>
            <div class="progress-step">
                <div class="progress-step__circle">2</div>
                <span class="progress-step__label">Contact</span>
            </div>
            <div class="progress-step">
                <div class="progress-step__circle">3</div>
                <span class="progress-step__label">Coverage</span>
            </div>
        </div>
        
        <form method="POST" action="" id="agencyForm">
            
            <!-- Step 1: Agency Information -->
            <div class="form-step active" id="step1">
                <div class="form-group">
                    <label class="form-group__label" for="agency_name">Agency Name *</label>
                    <input type="text" class="form-group__input" id="agency_name" name="agency_name" required 
                           placeholder="e.g., Times News Agency">
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="agency_type">Agency Type *</label>
                    <select class="form-group__select" id="agency_type" name="agency_type" required>
                        <option value="">Select type...</option>
                        <option value="national">National</option>
                        <option value="international">International</option>
                        <option value="local">Local / Regional</option>
                        <option value="digital">Digital Only</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="country">Country</label>
                    <input type="text" class="form-group__input" id="country" name="country" 
                           placeholder="e.g., India">
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="website">Website</label>
                    <input type="url" class="form-group__input" id="website" name="website" 
                           placeholder="https://www.youragency.com">
                </div>
                
                <div class="form-actions">
                    <span></span>
                    <button type="button" class="form-btn form-btn--primary" onclick="nextStep()">
                        Next Step →
                    </button>
                </div>
            </div>
            
            <!-- Step 2: Contact Information -->
            <div class="form-step" id="step2">
                <div class="form-group">
                    <label class="form-group__label" for="contact_name">Contact Person Name *</label>
                    <input type="text" class="form-group__input" id="contact_name" name="contact_name" required
                           placeholder="Full name">
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="contact_email">Email Address *</label>
                    <input type="email" class="form-group__input" id="contact_email" name="contact_email" required
                           placeholder="email@agency.com">
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="contact_phone">Phone Number</label>
                    <input type="tel" class="form-group__input" id="contact_phone" name="contact_phone"
                           placeholder="+91 98765 43210">
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="designation">Designation</label>
                    <input type="text" class="form-group__input" id="designation" name="designation"
                           placeholder="e.g., Editor-in-Chief">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="form-btn form-btn--secondary" onclick="prevStep()">
                        ← Previous
                    </button>
                    <button type="button" class="form-btn form-btn--primary" onclick="nextStep()">
                        Next Step →
                    </button>
                </div>
            </div>
            
            <!-- Step 3: Coverage & Description -->
            <div class="form-step" id="step3">
                <div class="form-group">
                    <label class="form-group__label">Coverage Areas</label>
                    <div class="checkbox-group">
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="politics"> Politics
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="sports"> Sports
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="business"> Business
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="entertainment"> Entertainment
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="technology"> Technology
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="health"> Health
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="world"> World
                        </label>
                        <label class="checkbox-item">
                            <input type="checkbox" name="coverage_areas[]" value="local"> Local News
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-group__label" for="description">About Your Agency</label>
                    <textarea class="form-group__textarea" id="description" name="description" rows="4"
                              placeholder="Tell us about your agency, experience, and why you want to partner with NewsXpressLive..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-group__label">Logo Upload</label>
                    <div style="padding: 2rem; border: 2px dashed var(--color-border); border-radius: var(--radius); text-align: center; color: var(--color-gray);">
                        📁 Logo upload will be available after approval<br>
                        <small>Supported formats: PNG, JPG (max 2MB)</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="form-btn form-btn--secondary" onclick="prevStep()">
                        ← Previous
                    </button>
                    <button type="submit" class="form-btn form-btn--primary">
                        Submit Application 🚀
                    </button>
                </div>
            </div>
            
        </form>
    </div>
    
    <?php endif; ?>

</div><!-- /.layout-main -->
</div><!-- /.container .page-body -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
