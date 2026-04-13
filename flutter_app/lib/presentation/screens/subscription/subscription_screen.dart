import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

class SubscriptionScreen extends StatefulWidget {
  const SubscriptionScreen({super.key});

  @override
  State<SubscriptionScreen> createState() => _SubscriptionScreenState();
}

class _SubscriptionScreenState extends State<SubscriptionScreen> {
  List<dynamic> _plans = [];
  Map<String, dynamic>? _currentSub;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    try {
      final plansRes = await http.get(Uri.parse('/api/subscription/plans.php'));
      final statusRes = await http.get(
        Uri.parse('/api/subscription/status.php'),
        headers: {'Authorization': 'Bearer TOKEN_HERE'},
      );
      if (plansRes.statusCode == 200) {
        final data = json.decode(plansRes.body);
        setState(() => _plans = data['plans'] ?? []);
      }
      if (statusRes.statusCode == 200) {
        final data = json.decode(statusRes.body);
        setState(() => _currentSub = data['subscription']);
      }
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _subscribe(int planId, String billing) async {
    final res = await http.post(
      Uri.parse('/api/subscription/create.php'),
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer TOKEN_HERE',
      },
      body: json.encode({'plan_id': planId, 'billing': billing, 'gateway': 'razorpay'}),
    );
    final data = json.decode(res.body);
    if (data['success'] == true && data['short_url'] != null) {
      // Open Razorpay short URL
      // launchUrl(Uri.parse(data['short_url']));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Redirecting to payment: ${data['short_url']}')),
        );
      }
    } else {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(data['error'] ?? 'Failed to create subscription')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Premium Subscription'),
        backgroundColor: const Color(0xFFCC0000),
        foregroundColor: Colors.white,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (_currentSub != null) _buildCurrentSubCard(),
                      const SizedBox(height: 16),
                      const Text(
                        'Choose Your Plan',
                        style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 12),
                      ..._plans.map(_buildPlanCard),
                    ],
                  ),
                ),
    );
  }

  Widget _buildCurrentSubCard() {
    final sub = _currentSub!;
    final status = sub['status'] as String? ?? '';
    final isActive = status == 'active';
    return Card(
      color: isActive ? Colors.green.shade50 : Colors.orange.shade50,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Icon(isActive ? Icons.verified : Icons.warning,
                   color: isActive ? Colors.green : Colors.orange),
              const SizedBox(width: 8),
              Text(
                isActive ? 'Premium Active' : 'Subscription ${status.toUpperCase()}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ]),
            if (sub['current_end'] != null) ...[
              const SizedBox(height: 4),
              Text(
                isActive ? 'Renews: ${sub['current_end']}' : 'Expired: ${sub['current_end']}',
                style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
              ),
            ],
            if (isActive) ...[
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: () => _showCancelDialog(),
                style: OutlinedButton.styleFrom(foregroundColor: Colors.red),
                child: const Text('Cancel Subscription'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildPlanCard(dynamic plan) {
    final features = (plan['features'] as List?) ?? [];
    final monthly = plan['price_monthly'] as double? ?? 0;
    final yearly  = plan['price_yearly'] as double? ?? 0;
    final savings  = plan['yearly_savings'] as double? ?? 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      elevation: 3,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              plan['name'] ?? '',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
            ),
            Text(
              plan['name_hi'] ?? '',
              style: TextStyle(fontSize: 14, color: Colors.grey.shade600),
            ),
            const Divider(height: 20),
            ...features.map((f) => Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(children: [
                const Icon(Icons.check_circle, color: Colors.green, size: 18),
                const SizedBox(width: 8),
                Expanded(child: Text(f.toString(), style: const TextStyle(fontSize: 14))),
              ]),
            )),
            const SizedBox(height: 16),
            Row(children: [
              Expanded(child: _buildBillingButton(
                '₹${monthly.toStringAsFixed(0)}/month',
                'Monthly',
                () => _subscribe(plan['id'], 'monthly'),
                isHighlighted: false,
              )),
              const SizedBox(width: 10),
              Expanded(child: _buildBillingButton(
                '₹${yearly.toStringAsFixed(0)}/year\nSave ₹${savings.toStringAsFixed(0)}!',
                'Yearly',
                () => _subscribe(plan['id'], 'yearly'),
                isHighlighted: true,
              )),
            ]),
          ],
        ),
      ),
    );
  }

  Widget _buildBillingButton(String label, String period, VoidCallback onTap, {bool isHighlighted = false}) {
    return ElevatedButton(
      onPressed: onTap,
      style: ElevatedButton.styleFrom(
        backgroundColor: isHighlighted ? const Color(0xFFCC0000) : Colors.grey.shade200,
        foregroundColor: isHighlighted ? Colors.white : Colors.black87,
        padding: const EdgeInsets.symmetric(vertical: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      ),
      child: Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 13)),
    );
  }

  void _showCancelDialog() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Cancel Subscription'),
        content: const Text('Your subscription will remain active until the end of the current billing period.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Keep Premium')),
          TextButton(
            onPressed: () { Navigator.pop(ctx); /* Cancel logic */ },
            style: TextButton.styleFrom(foregroundColor: Colors.red),
            child: const Text('Cancel'),
          ),
        ],
      ),
    );
  }
}
