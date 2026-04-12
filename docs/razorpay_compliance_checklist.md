## Razorpay Live Account + IT Act 2000 + RBI Compliance Checklist

### Required URLs / Pages (Razorpay requires these before approving live mode)

| Page | URL (Example) | Status |
|------|--------------|--------|
| Home Page | https://newsxpresslive.com/ | ✓ |
| About Us | https://newsxpresslive.com/about | Required |
| Contact Us | https://newsxpresslive.com/contact | Required |
| **Terms of Service** | https://newsxpresslive.com/legal/terms.html | ✓ Created |
| **Privacy Policy** | https://newsxpresslive.com/legal/privacy.html | ✓ Created |
| **Refund / Cancellation Policy** | https://newsxpresslive.com/legal/refund.html | ✓ Created |
| Pricing / Plans Page | https://newsxpresslive.com/plans | Required |
| Support / Help | https://newsxpresslive.com/support | Required |
| Grievance Officer Page | https://newsxpresslive.com/grievance | Required |

Razorpay additionally checks:
- Business entity legally registered in India (Pvt Ltd / LLP / Proprietorship)
- GST registration (if turnover > ₹20L)
- Bank account in business name
- Website must be live and not under construction
- "Powered by Razorpay" or payment logo visible on checkout

---

### IT Act 2000 Compliance Checklist

- [x] **Section 43A** — Implemented reasonable security practices (CORS, CSP, CSRF, encrypted fields)
- [x] **Section 66** — Unauthorised access protection (admin auth tokens, Firebase auth)
- [x] **Section 67 / 67A / 67B** — Content moderation 3-strikes system prevents obscene/CSAM content
- [x] **Section 69B** — User activity logs retained for 180 days (IT Rules, 2021 Rule 3(1)(j))
- [x] **Section 79** — Intermediary liability: Grievance Officer published, complaints mechanism live
- [x] **IT Rules 2021, Rule 3** — Terms of Service + Privacy Policy + Refund Policy published
- [x] **IT Rules 2021, Rule 4** — Grievance Officer contact details published
- [ ] **IT Rules 2021, Rule 4(4)** — Publish monthly compliance report (for significant social media intermediaries with >5M users — not yet applicable)
- [x] **DPDP Act 2023** — Privacy Policy is DPDP Act 2023 compliant (Data Fiduciary, consent, Data Principal rights, DPO published)
- [x] **Consumer Protection Act 2019** — Refund policy references NCDRC; consumer helpline number published

---

### RBI Guidelines Compliance Checklist (for Razorpay Payment Aggregator)

- [x] Payment Aggregator: Using Razorpay (RBI authorised PA per March 2020 guidelines)
- [x] No raw card data stored on platform servers (Razorpay tokenisation)
- [x] PCI-DSS: Delegated to Razorpay; confirmed in Privacy Policy
- [x] Transaction records retained for 5 years (per RBI circular)
- [x] Refund mechanism implemented (auto-refund on rejection, manual via admin API)
- [x] Webhook signature verification (HMAC-SHA256)
- [x] Dispute/chargeback handling implemented
- [x] GST (18%) applied on Blue Tick fee; note in Refund Policy
- [x] TDS deduction note for reporter earnings > ₹30,000/year
- [ ] **RBI KYC norms** — Implement full KYC (bank account + PAN verification for withdrawals > ₹50,000)
- [ ] **FEMA 1999** — Not applicable (India-only payments; no cross-border remittance)
- [ ] **PPI Licence** — Not required (Razorpay holds PPI licence; we are a merchant)

---

### Additional Legal Steps Before Going Live

1. Register company with MCA (Ministry of Corporate Affairs) — PVT Ltd recommended
2. Get GST registration (GSTIN) — mandatory for collecting payments
3. Open a current bank account in company name
4. Register with Razorpay and complete KYB (Know Your Business) verification
5. Add "Grievance Officer" page to website with name, address, phone, email
6. Ensure SSL/HTTPS certificate is valid on production domain
7. Publish an "About Us" page describing the business
8. Consider registering as a news aggregator with MIB (Ministry of Information and Broadcasting) — recommended but not legally mandated for aggregators
