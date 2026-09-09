import 'dart:io';

import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../data/models/blue_tick_model.dart';
import '../../../data/services/verification_service.dart';
import '../../../providers/auth_provider.dart';

/// Screen that lets a reporter or agency:
///  1. View their current verification status.
///  2. Submit KYC documents if not yet verified.
///  3. See available blue-tick plans and claim/purchase one.
class VerificationScreen extends StatefulWidget {
  const VerificationScreen({super.key});

  @override
  State<VerificationScreen> createState() => _VerificationScreenState();
}

class _VerificationScreenState extends State<VerificationScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  bool _loading = true;
  String? _error;

  UserVerificationInfo? _statusInfo;
  List<BlueTickPlan>    _plans    = [];
  EarlyBirdInfo?        _ebReporter;
  EarlyBirdInfo?        _ebAgency;
  List<MediaChannel>    _channels = [];

  // Form controllers
  final _aadharCtrl    = TextEditingController();
  final _panCtrl       = TextEditingController();
  final _channelCtrl   = TextEditingController();
  final _bizNameCtrl   = TextEditingController();
  final _contactCtrl   = TextEditingController();
  final _phoneCtrl     = TextEditingController();
  final _msmeCtrl      = TextEditingController();
  final _websiteCtrl   = TextEditingController();

  String  _accountType     = 'reporter';
  int?    _selectedChannel;
  File?   _aadharDoc;
  File?   _panDoc;
  File?   _msmeDoc;
  File?   _legalDoc;
  bool    _submitting      = false;

  late VerificationService _svc;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _init());
  }

  Future<void> _init() async {
    final auth = context.read<AuthProvider>();
    final idToken = await auth.getIdToken();
    if (idToken == null) {
      setState(() { _loading = false; _error = 'Not signed in'; });
      return;
    }
    _svc = VerificationService(idToken);
    await _loadAll();
  }

  Future<void> _loadAll() async {
    setState(() { _loading = true; _error = null; });
    try {
      final results = await Future.wait([
        _svc.fetchStatus(),
        _svc.fetchPlans(),
        _svc.fetchChannels(),
      ]);
      final status   = results[0] as UserVerificationInfo;
      final plansMap = results[1] as Map<String, dynamic>;
      final channels = results[2] as List<MediaChannel>;

      setState(() {
        _statusInfo  = status;
        _plans       = plansMap['plans'] as List<BlueTickPlan>;
        _ebReporter  = (plansMap['early_bird'] as Map)['reporter'] as EarlyBirdInfo;
        _ebAgency    = (plansMap['early_bird'] as Map)['agency']   as EarlyBirdInfo;
        _channels    = channels;
        _loading     = false;
      });
    } catch (e) {
      setState(() { _loading = false; _error = e.toString(); });
    }
  }

  @override
  void dispose() {
    _tabController.dispose();
    _aadharCtrl.dispose();
    _panCtrl.dispose();
    _channelCtrl.dispose();
    _bizNameCtrl.dispose();
    _contactCtrl.dispose();
    _phoneCtrl.dispose();
    _msmeCtrl.dispose();
    _websiteCtrl.dispose();
    super.dispose();
  }

  // ── Submit documents ────────────────────────────────────────────────────
  Future<void> _submitDocuments() async {
    if (_submitting) return;
    setState(() { _submitting = true; });
    try {
      if (_accountType == 'reporter') {
        if (_aadharDoc == null) {
          _showSnack('Please attach your Aadhar document');
          return;
        }
        await _svc.submitReporterVerification(
          aadharNumber: _aadharCtrl.text.trim(),
          panNumber:    _panCtrl.text.trim().isEmpty ? null : _panCtrl.text.trim(),
          channelId:    _selectedChannel,
          channelName:  _channelCtrl.text.trim().isEmpty ? null : _channelCtrl.text.trim(),
          aadharDoc:    _aadharDoc!,
          panDoc:       _panDoc,
        );
      } else {
        await _svc.submitAgencyVerification(
          businessName:  _bizNameCtrl.text.trim(),
          contactName:   _contactCtrl.text.trim(),
          contactPhone:  _phoneCtrl.text.trim(),
          msmeNumber:    _msmeCtrl.text.trim().isEmpty  ? null : _msmeCtrl.text.trim(),
          website:       _websiteCtrl.text.trim().isEmpty ? null : _websiteCtrl.text.trim(),
          msmeDoc:       _msmeDoc,
          legalDoc:      _legalDoc,
        );
      }
      if (mounted) {
        _showSnack('Documents submitted! We\'ll review within 48 hours.', success: true);
        await _loadAll();
      }
    } catch (e) {
      _showSnack(e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) setState(() { _submitting = false; });
    }
  }

  // ── Claim / purchase plan ───────────────────────────────────────────────
  Future<void> _claimPlan(BlueTickPlan plan) async {
    setState(() { _submitting = true; });
    try {
      final result = await _svc.purchasePlan(planId: plan.id);
      if (result['blue_tick'] == true) {
        _showSnack('🎉 Blue tick granted!', success: true);
        await _loadAll();
      } else if (result['payment_required'] == true) {
        _showSnack('Payment required — Razorpay integration coming soon.', success: false);
      } else {
        _showSnack(result['message'] ?? 'Something went wrong');
      }
    } catch (e) {
      _showSnack(e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) setState(() { _submitting = false; });
    }
  }

  void _showSnack(String msg, {bool success = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: success ? Colors.green : Colors.red,
    ));
  }

  Future<void> _pickFile(String field) async {
    final result = await FilePicker.platform.pickFiles(
      allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
      type: FileType.custom,
    );
    if (result == null || result.files.isEmpty) return;
    final file = File(result.files.first.path!);
    setState(() {
      switch (field) {
        case 'aadhar': _aadharDoc = file;
        case 'pan':    _panDoc    = file;
        case 'msme':   _msmeDoc   = file;
        case 'legal':  _legalDoc  = file;
      }
    });
  }

  // ═══════════════════════════════════════════════════════════════════════
  // Build
  // ═══════════════════════════════════════════════════════════════════════
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Blue Tick Verification'),
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(icon: Icon(Icons.info_outline),  text: 'Status'),
            Tab(icon: Icon(Icons.upload_file),   text: 'Documents'),
            Tab(icon: Icon(Icons.verified_user), text: 'Plans'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _ErrorView(error: _error!, onRetry: _loadAll)
              : TabBarView(
                  controller: _tabController,
                  children: [
                    _StatusTab(info: _statusInfo!),
                    _DocumentsTab(
                      accountType:     _accountType,
                      onAccountTypeChange: (t) => setState(() => _accountType = t),
                      channels:        _channels,
                      selectedChannel: _selectedChannel,
                      onChannelChange: (id) => setState(() => _selectedChannel = id),
                      aadharCtrl:   _aadharCtrl,
                      panCtrl:      _panCtrl,
                      channelCtrl:  _channelCtrl,
                      bizNameCtrl:  _bizNameCtrl,
                      contactCtrl:  _contactCtrl,
                      phoneCtrl:    _phoneCtrl,
                      msmeCtrl:     _msmeCtrl,
                      websiteCtrl:  _websiteCtrl,
                      aadharDoc:    _aadharDoc,
                      panDoc:       _panDoc,
                      msmeDoc:      _msmeDoc,
                      legalDoc:     _legalDoc,
                      onPickFile:   _pickFile,
                      onSubmit:     _submitting ? null : _submitDocuments,
                      submitting:   _submitting,
                      existingStatus: _statusInfo?.verificationStatus,
                    ),
                    _PlansTab(
                      plans:       _plans,
                      ebReporter:  _ebReporter,
                      ebAgency:    _ebAgency,
                      onClaim:     _submitting ? null : _claimPlan,
                      hasBlueTick: _statusInfo?.isBlueTick ?? false,
                    ),
                  ],
                ),
    );
  }
}

// ── Status tab ──────────────────────────────────────────────────────────────

class _StatusTab extends StatelessWidget {
  final UserVerificationInfo info;
  const _StatusTab({required this.info});

  @override
  Widget build(BuildContext context) {
    final colorScheme = Theme.of(context).colorScheme;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _StatusCard(
          title: 'Account Type',
          value: info.accountType.name.toUpperCase(),
          icon:  Icons.person,
        ),
        _StatusCard(
          title:  'Verification',
          value:  info.verificationStatus.name.toUpperCase(),
          icon:   Icons.verified_outlined,
          color:  _statusColor(info.verificationStatus, colorScheme),
        ),
        _StatusCard(
          title: 'Blue Tick',
          value: info.isBlueTick
              ? '✓ Active (${info.blueTickType?.label ?? ''})'
              : 'Not granted',
          icon:  Icons.verified,
          color: info.isBlueTick ? Colors.blue : null,
        ),
        _StatusCard(
          title: 'Profile',
          value: info.profileComplete ? 'Complete' : 'Incomplete',
          icon:  Icons.manage_accounts,
        ),
        if (info.submission != null) ...[
          const SizedBox(height: 16),
          const Text('Latest Submission', style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          Card(
            child: ListTile(
              leading: const Icon(Icons.description_outlined),
              title:   Text('Status: ${info.submission!.status.name}'),
              subtitle:Text('Submitted: ${info.submission!.submittedAt}'),
              trailing: info.submission!.rejectionReason != null
                  ? const Icon(Icons.error_outline, color: Colors.red)
                  : null,
            ),
          ),
          if (info.submission!.rejectionReason != null)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              child: Text(
                'Reason: ${info.submission!.rejectionReason}',
                style: const TextStyle(color: Colors.red, fontSize: 13),
              ),
            ),
        ],
      ],
    );
  }

  Color? _statusColor(VerificationStatus s, ColorScheme cs) => switch (s) {
    VerificationStatus.approved => Colors.green,
    VerificationStatus.rejected => Colors.red,
    VerificationStatus.pending  => Colors.orange,
    _                           => null,
  };
}

class _StatusCard extends StatelessWidget {
  final String title, value;
  final IconData icon;
  final Color? color;
  const _StatusCard({required this.title, required this.value,
                     required this.icon, this.color});

  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.symmetric(vertical: 6),
    child: ListTile(
      leading: Icon(icon, color: color ?? Theme.of(context).colorScheme.primary),
      title:   Text(title),
      trailing:Text(value,
          style: TextStyle(fontWeight: FontWeight.bold, color: color)),
    ),
  );
}

// ── Documents tab ──────────────────────────────────────────────────────────

class _DocumentsTab extends StatelessWidget {
  final String accountType;
  final ValueChanged<String> onAccountTypeChange;
  final List<MediaChannel>   channels;
  final int?                 selectedChannel;
  final ValueChanged<int?>   onChannelChange;
  final TextEditingController aadharCtrl, panCtrl, channelCtrl,
                              bizNameCtrl, contactCtrl, phoneCtrl,
                              msmeCtrl, websiteCtrl;
  final File? aadharDoc, panDoc, msmeDoc, legalDoc;
  final void Function(String) onPickFile;
  final VoidCallback? onSubmit;
  final bool submitting;
  final VerificationStatus? existingStatus;

  const _DocumentsTab({
    required this.accountType,
    required this.onAccountTypeChange,
    required this.channels,
    required this.selectedChannel,
    required this.onChannelChange,
    required this.aadharCtrl,
    required this.panCtrl,
    required this.channelCtrl,
    required this.bizNameCtrl,
    required this.contactCtrl,
    required this.phoneCtrl,
    required this.msmeCtrl,
    required this.websiteCtrl,
    required this.aadharDoc,
    required this.panDoc,
    required this.msmeDoc,
    required this.legalDoc,
    required this.onPickFile,
    required this.onSubmit,
    required this.submitting,
    required this.existingStatus,
  });

  @override
  Widget build(BuildContext context) {
    if (existingStatus == VerificationStatus.approved) {
      return const Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.verified, size: 64, color: Colors.blue),
          SizedBox(height: 12),
          Text('Verification approved!', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
        ]),
      );
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        // Account type selector
        const Text('I am a:', style: TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'reporter', label: Text('Reporter'), icon: Icon(Icons.mic)),
            ButtonSegment(value: 'agency',   label: Text('Agency'),   icon: Icon(Icons.business)),
          ],
          selected: {accountType},
          onSelectionChanged: (s) => onAccountTypeChange(s.first),
        ),
        const SizedBox(height: 16),

        if (accountType == 'reporter') ...[
          _FieldTile(ctrl: aadharCtrl, label: 'Aadhar Number *', hint: '12 digits', keyboard: TextInputType.number),
          _FieldTile(ctrl: panCtrl,    label: 'PAN Number',       hint: 'ABCDE1234F'),
          // Channel dropdown
          DropdownButtonFormField<int?>(
            value: selectedChannel,
            decoration: const InputDecoration(labelText: 'Media Channel (optional)', border: OutlineInputBorder()),
            items: [
              const DropdownMenuItem(value: null, child: Text('Other / Not listed')),
              ...channels.map((c) => DropdownMenuItem(value: c.id, child: Text(c.name))),
            ],
            onChanged: onChannelChange,
          ),
          if (selectedChannel == null)
            _FieldTile(ctrl: channelCtrl, label: 'Channel Name (if other)', hint: 'e.g. Local TV'),
          const SizedBox(height: 8),
          _FileTile(label: 'Aadhar Document *', file: aadharDoc, onPick: () => onPickFile('aadhar')),
          _FileTile(label: 'PAN Document',       file: panDoc,    onPick: () => onPickFile('pan')),
        ] else ...[
          _FieldTile(ctrl: bizNameCtrl, label: 'Business Name *',   hint: 'ABC News Pvt Ltd'),
          _FieldTile(ctrl: contactCtrl, label: 'Contact Person *',  hint: 'Full name'),
          _FieldTile(ctrl: phoneCtrl,   label: 'Phone Number *',    hint: '10 digits', keyboard: TextInputType.phone),
          _FieldTile(ctrl: msmeCtrl,    label: 'MSME Number',       hint: 'Optional'),
          _FieldTile(ctrl: websiteCtrl, label: 'Website',           hint: 'https://...', keyboard: TextInputType.url),
          const SizedBox(height: 8),
          _FileTile(label: 'MSME Certificate', file: msmeDoc,  onPick: () => onPickFile('msme')),
          _FileTile(label: 'Legal Document',   file: legalDoc, onPick: () => onPickFile('legal')),
        ],

        const SizedBox(height: 24),
        ElevatedButton.icon(
          onPressed: submitting ? null : onSubmit,
          icon:  submitting
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.send),
          label: Text(submitting ? 'Submitting…' : 'Submit for Verification'),
          style: ElevatedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
        ),

        if (existingStatus == VerificationStatus.pending)
          const Padding(
            padding: EdgeInsets.only(top: 12),
            child: Text(
              'Your previous submission is under review. You can re-submit to update your documents.',
              style: TextStyle(color: Colors.orange, fontSize: 12),
              textAlign: TextAlign.center,
            ),
          ),
      ]),
    );
  }
}

class _FieldTile extends StatelessWidget {
  final TextEditingController ctrl;
  final String label, hint;
  final TextInputType keyboard;
  const _FieldTile({required this.ctrl, required this.label,
                    required this.hint, this.keyboard = TextInputType.text});

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      controller: ctrl,
      keyboardType: keyboard,
      decoration: InputDecoration(
        labelText: label, hintText: hint, border: const OutlineInputBorder()),
    ),
  );
}

class _FileTile extends StatelessWidget {
  final String label;
  final File? file;
  final VoidCallback onPick;
  const _FileTile({required this.label, required this.file, required this.onPick});

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: OutlinedButton.icon(
      onPressed: onPick,
      icon: Icon(file != null ? Icons.check_circle : Icons.attach_file,
                 color: file != null ? Colors.green : null),
      label: Text(file != null ? '$label ✓' : 'Attach $label'),
    ),
  );
}

// ── Plans tab ──────────────────────────────────────────────────────────────

class _PlansTab extends StatelessWidget {
  final List<BlueTickPlan> plans;
  final EarlyBirdInfo?     ebReporter;
  final EarlyBirdInfo?     ebAgency;
  final void Function(BlueTickPlan)? onClaim;
  final bool hasBlueTick;

  const _PlansTab({
    required this.plans,
    required this.ebReporter,
    required this.ebAgency,
    required this.onClaim,
    required this.hasBlueTick,
  });

  @override
  Widget build(BuildContext context) {
    if (hasBlueTick) {
      return const Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.verified, size: 64, color: Colors.blue),
          SizedBox(height: 12),
          Text('You already have a Blue Tick!',
               style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
        ]),
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (ebReporter != null && ebReporter!.freeAvailable)
          _EarlyBirdBanner(
            label:     '🎉 Reporter Early-Bird',
            remaining: ebReporter!.remaining,
            limit:     ebReporter!.freeLimit,
          ),
        if (ebAgency != null && ebAgency!.freeAvailable)
          _EarlyBirdBanner(
            label:     '🎉 Agency Early-Bird',
            remaining: ebAgency!.remaining,
            limit:     ebAgency!.freeLimit,
          ),
        const SizedBox(height: 8),
        ...plans.map((p) => _PlanCard(
          plan:   p,
          onClaim: onClaim != null ? () => onClaim!(p) : null,
        )),
      ],
    );
  }
}

class _EarlyBirdBanner extends StatelessWidget {
  final String label;
  final int remaining, limit;
  const _EarlyBirdBanner({required this.label, required this.remaining, required this.limit});

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 12),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color:        Colors.amber.shade50,
      border:       Border.all(color: Colors.amber),
      borderRadius: BorderRadius.circular(8),
    ),
    child: Row(children: [
      const Icon(Icons.local_fire_department, color: Colors.orange),
      const SizedBox(width: 8),
      Expanded(child: Text('$label: $remaining / $limit free slots remaining',
                           style: const TextStyle(fontWeight: FontWeight.w600))),
    ]),
  );
}

class _PlanCard extends StatelessWidget {
  final BlueTickPlan plan;
  final VoidCallback? onClaim;
  const _PlanCard({required this.plan, required this.onClaim});

  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.only(bottom: 12),
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.verified, color: Colors.blue),
          const SizedBox(width: 8),
          Expanded(child: Text(plan.name,
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold))),
          Text(plan.priceLabel,
              style: TextStyle(
                color: plan.isFree ? Colors.green : Theme.of(context).colorScheme.primary,
                fontWeight: FontWeight.bold,
                fontSize: 18,
              )),
        ]),
        const SizedBox(height: 8),
        Text('Duration: ${plan.durationType}'),
        if (plan.canAssignTicks)
          Text('Includes ${plan.assignLimit} reporter tick assignments'
               '${plan.assignPrice > 0 ? " + ₹${plan.assignPrice.toStringAsFixed(0)} each extra" : ""}'),
        if (plan.earlyBirdAvailable && plan.isFree)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Chip(
              label: const Text('Free Early-Bird Slot Available'),
              backgroundColor: Colors.green.shade50,
              labelStyle: const TextStyle(color: Colors.green),
            ),
          ),
        if (plan.slotsRemaining != null)
          Text('${plan.slotsRemaining} slots remaining',
               style: const TextStyle(color: Colors.grey, fontSize: 12)),
        const SizedBox(height: 12),
        SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: onClaim,
            child: Text(plan.isFree ? 'Claim Free Tick' : 'Get Blue Tick for ${plan.priceLabel}'),
          ),
        ),
      ]),
    ),
  );
}

// ── Error view ─────────────────────────────────────────────────────────────

class _ErrorView extends StatelessWidget {
  final String error;
  final VoidCallback onRetry;
  const _ErrorView({required this.error, required this.onRetry});

  @override
  Widget build(BuildContext context) => Center(
    child: Column(mainAxisSize: MainAxisSize.min, children: [
      const Icon(Icons.error_outline, size: 48, color: Colors.red),
      const SizedBox(height: 12),
      Text(error, textAlign: TextAlign.center),
      const SizedBox(height: 16),
      ElevatedButton(onPressed: onRetry, child: const Text('Retry')),
    ]),
  );
}
