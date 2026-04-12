/// Models for the Blue Tick Verification system.
library;

// ── VerificationStatus ──────────────────────────────────────────────────────

enum VerificationStatus {
  unverified,
  pending,
  approved,
  rejected;

  static VerificationStatus fromString(String? s) => switch (s) {
        'pending'  => VerificationStatus.pending,
        'approved' => VerificationStatus.approved,
        'rejected' => VerificationStatus.rejected,
        _          => VerificationStatus.unverified,
      };
}

// ── BlueTickType ───────────────────────────────────────────────────────────

enum BlueTickType {
  free,
  paid,
  agencyAssigned;

  static BlueTickType? fromString(String? s) => switch (s) {
        'free'             => BlueTickType.free,
        'paid'             => BlueTickType.paid,
        'agency_assigned'  => BlueTickType.agencyAssigned,
        _                  => null,
      };

  String get label => switch (this) {
        BlueTickType.free            => 'Free',
        BlueTickType.paid            => 'Paid',
        BlueTickType.agencyAssigned  => 'Agency',
      };
}

// ── AccountType ────────────────────────────────────────────────────────────

enum AccountType {
  user,
  reporter,
  agency;

  static AccountType fromString(String? s) => switch (s) {
        'reporter' => AccountType.reporter,
        'agency'   => AccountType.agency,
        _          => AccountType.user,
      };
}

// ── BlueTickPlan ───────────────────────────────────────────────────────────

class BlueTickPlan {
  final int    id;
  final String planType;
  final String name;
  final double price;
  final String durationType;
  final int    maxReporters;
  final bool   canAssignTicks;
  final int    assignLimit;
  final double assignPrice;
  final bool   earlyBirdAvailable;
  final int?   slotsRemaining;

  const BlueTickPlan({
    required this.id,
    required this.planType,
    required this.name,
    required this.price,
    required this.durationType,
    required this.maxReporters,
    required this.canAssignTicks,
    required this.assignLimit,
    required this.assignPrice,
    required this.earlyBirdAvailable,
    this.slotsRemaining,
  });

  bool get isFree       => price == 0.0;
  bool get isReporter   => planType.startsWith('reporter');
  bool get isAgency     => planType.startsWith('agency');

  String get priceLabel => isFree ? 'FREE' : '₹${price.toStringAsFixed(0)}';

  factory BlueTickPlan.fromJson(Map<String, dynamic> j) => BlueTickPlan(
    id:                  (j['id']    as num).toInt(),
    planType:            j['plan_type']   as String,
    name:                j['name']        as String,
    price:               (j['price']      as num).toDouble(),
    durationType:        j['duration_type'] as String,
    maxReporters:        (j['max_reporters'] as num).toInt(),
    canAssignTicks:      j['can_assign_ticks'] == true,
    assignLimit:         (j['assign_limit']  as num).toInt(),
    assignPrice:         (j['assign_price']  as num).toDouble(),
    earlyBirdAvailable:  j['early_bird_available'] == true,
    slotsRemaining:      j['slots_remaining'] != null
                           ? (j['slots_remaining'] as num).toInt()
                           : null,
  );
}

// ── EarlyBirdInfo ──────────────────────────────────────────────────────────

class EarlyBirdInfo {
  final int  count;
  final int  freeLimit;
  final int  paidLimit;
  final bool freeAvailable;

  const EarlyBirdInfo({
    required this.count,
    required this.freeLimit,
    required this.paidLimit,
    required this.freeAvailable,
  });

  int get remaining => freeAvailable ? (freeLimit - count).clamp(0, freeLimit) : 0;

  factory EarlyBirdInfo.fromJson(Map<String, dynamic> j) => EarlyBirdInfo(
    count:         (j['count']          as num).toInt(),
    freeLimit:     (j['free_limit']     as num).toInt(),
    paidLimit:     (j['paid_limit']     as num).toInt(),
    freeAvailable:  j['free_available'] == true,
  );
}

// ── VerificationSubmission ─────────────────────────────────────────────────

class VerificationSubmission {
  final int               id;
  final String            accountType;
  final VerificationStatus status;
  final String            submittedAt;
  final String?           rejectionReason;

  const VerificationSubmission({
    required this.id,
    required this.accountType,
    required this.status,
    required this.submittedAt,
    this.rejectionReason,
  });

  factory VerificationSubmission.fromJson(Map<String, dynamic> j) =>
      VerificationSubmission(
        id:              (j['id'] as num).toInt(),
        accountType:      j['account_type'] as String,
        status:           VerificationStatus.fromString(j['status'] as String?),
        submittedAt:      j['submitted_at']   as String,
        rejectionReason:  j['rejection_reason'] as String?,
      );
}

// ── UserVerificationInfo ───────────────────────────────────────────────────

class UserVerificationInfo {
  final VerificationStatus  verificationStatus;
  final AccountType         accountType;
  final bool                isBlueTick;
  final BlueTickType?       blueTickType;
  final String?             blueTickGrantedAt;
  final bool                profileComplete;
  final VerificationSubmission? submission;

  const UserVerificationInfo({
    required this.verificationStatus,
    required this.accountType,
    required this.isBlueTick,
    required this.blueTickType,
    this.blueTickGrantedAt,
    required this.profileComplete,
    this.submission,
  });

  factory UserVerificationInfo.fromJson(Map<String, dynamic> j) =>
      UserVerificationInfo(
        verificationStatus: VerificationStatus.fromString(j['verification_status'] as String?),
        accountType:        AccountType.fromString(j['account_type'] as String?),
        isBlueTick:         j['is_blue_tick'] == true,
        blueTickType:       BlueTickType.fromString(j['blue_tick_type'] as String?),
        blueTickGrantedAt:  j['blue_tick_granted_at'] as String?,
        profileComplete:    j['profile_complete'] == true,
        submission:         j['submission'] != null
                              ? VerificationSubmission.fromJson(
                                  j['submission'] as Map<String, dynamic>)
                              : null,
      );
}

// ── MediaChannel ──────────────────────────────────────────────────────────

class MediaChannel {
  final int    id;
  final String name;
  final String type;

  const MediaChannel({
    required this.id,
    required this.name,
    required this.type,
  });

  factory MediaChannel.fromJson(Map<String, dynamic> j) => MediaChannel(
    id:   (j['id'] as num).toInt(),
    name:  j['name'] as String,
    type:  j['type'] as String,
  );
}

// ── BlueTickAssignment ────────────────────────────────────────────────────

class BlueTickAssignment {
  final int    id;
  final String reporterId;
  final String assignmentType;
  final double amountCharged;
  final String assignedAt;
  final String status;
  final String? displayName;
  final String? avatarUrl;

  const BlueTickAssignment({
    required this.id,
    required this.reporterId,
    required this.assignmentType,
    required this.amountCharged,
    required this.assignedAt,
    required this.status,
    this.displayName,
    this.avatarUrl,
  });

  factory BlueTickAssignment.fromJson(Map<String, dynamic> j) => BlueTickAssignment(
    id:             (j['id'] as num).toInt(),
    reporterId:      j['reporter_id']     as String,
    assignmentType:  j['assignment_type'] as String,
    amountCharged:   (j['amount_charged'] as num).toDouble(),
    assignedAt:      j['assigned_at']     as String,
    status:          j['status']          as String,
    displayName:     j['display_name']    as String?,
    avatarUrl:       j['avatar_url']      as String?,
  );
}
