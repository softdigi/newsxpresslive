import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../data/models/agency_models.dart';
import '../../../providers/agency_provider.dart';

class AgencyWalletScreen extends StatefulWidget {
  const AgencyWalletScreen({super.key});

  @override
  State<AgencyWalletScreen> createState() => _AgencyWalletScreenState();
}

class _AgencyWalletScreenState extends State<AgencyWalletScreen> {
  final _amountCtrl = TextEditingController();
  final _accountCtrl = TextEditingController();
  String _method = 'UPI';

  static const _methods = ['UPI', 'Bank Transfer', 'PayPal'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<AgencyProvider>().loadWallet();
    });
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _accountCtrl.dispose();
    super.dispose();
  }

  Future<void> _requestWithdrawal() async {
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (amount < 500) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Minimum withdrawal amount is ₹500'),
            backgroundColor: AppColors.primary),
      );
      return;
    }
    if (_accountCtrl.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Please enter account details'),
            backgroundColor: AppColors.primary),
      );
      return;
    }
    final ok = await context
        .read<AgencyProvider>()
        .requestWithdrawal(amount, _method, _accountCtrl.text.trim());
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'Withdrawal requested!' : 'Request failed'),
        backgroundColor: ok ? Colors.green : AppColors.primary,
      ),
    );
    if (ok) {
      _amountCtrl.clear();
      _accountCtrl.clear();
      context.read<AgencyProvider>().loadWallet();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<AgencyProvider>(
      builder: (context, provider, _) {
        final balance = provider.agencyProfile?.walletBalance ?? 0.0;
        return Scaffold(
          backgroundColor: AppColors.scaffoldLight,
          appBar: AppBar(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            title: const Text('Wallet'),
          ),
          body: provider.isLoading && provider.transactions.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: () => provider.loadWallet(),
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      // Balance
                      _card(
                        child: Column(
                          children: [
                            const Text('Current Balance',
                                style: TextStyle(
                                    color: AppColors.textSecondaryLight,
                                    fontSize: 13)),
                            const SizedBox(height: 4),
                            Text(
                              '₹${balance.toStringAsFixed(2)}',
                              style: const TextStyle(
                                  fontSize: 36,
                                  fontWeight: FontWeight.bold,
                                  color: Colors.green),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),
                      // Withdraw form
                      _card(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('Request Withdrawal',
                                style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                    color: AppColors.textPrimaryLight)),
                            const SizedBox(height: 12),
                            TextField(
                              controller: _amountCtrl,
                              keyboardType:
                                  const TextInputType.numberWithOptions(
                                      decimal: true),
                              decoration: _inputDec('Amount (min ₹500)',
                                  prefix: '₹'),
                            ),
                            const SizedBox(height: 12),
                            DropdownButtonFormField<String>(
                              value: _method,
                              decoration: InputDecoration(
                                labelText: 'Payment Method',
                                border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(10)),
                                contentPadding: const EdgeInsets.symmetric(
                                    horizontal: 12, vertical: 12),
                              ),
                              items: _methods
                                  .map((m) => DropdownMenuItem(
                                      value: m, child: Text(m)))
                                  .toList(),
                              onChanged: (v) =>
                                  setState(() => _method = v!),
                            ),
                            const SizedBox(height: 12),
                            TextField(
                              controller: _accountCtrl,
                              decoration: _inputDec(
                                  'Account Details (UPI ID / Account No.)'),
                            ),
                            const SizedBox(height: 16),
                            SizedBox(
                              width: double.infinity,
                              child: ElevatedButton(
                                onPressed:
                                    provider.isLoading ? null : _requestWithdrawal,
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: Colors.green,
                                  foregroundColor: Colors.white,
                                  shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(10)),
                                  padding: const EdgeInsets.symmetric(
                                      vertical: 14),
                                ),
                                child: provider.isLoading
                                    ? const SizedBox(
                                        height: 18,
                                        width: 18,
                                        child: CircularProgressIndicator(
                                            color: Colors.white,
                                            strokeWidth: 2))
                                    : const Text('Request Withdrawal'),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),
                      // Transactions
                      const Text('Transaction History',
                          style: TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: AppColors.textPrimaryLight)),
                      const SizedBox(height: 8),
                      if (provider.transactions.isEmpty)
                        const Center(
                            child: Padding(
                          padding: EdgeInsets.all(16),
                          child: Text('No transactions yet',
                              style: TextStyle(
                                  color: AppColors.textSecondaryLight)),
                        ))
                      else
                        ...provider.transactions
                            .map((t) => _TransactionTile(t)),
                      const SizedBox(height: 16),
                      // Withdrawals
                      const Text('Withdrawal History',
                          style: TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: AppColors.textPrimaryLight)),
                      const SizedBox(height: 8),
                      if (provider.withdrawals.isEmpty)
                        const Center(
                            child: Padding(
                          padding: EdgeInsets.all(16),
                          child: Text('No withdrawals yet',
                              style: TextStyle(
                                  color: AppColors.textSecondaryLight)),
                        ))
                      else
                        ...provider.withdrawals
                            .map((w) => _WithdrawalTile(w)),
                    ],
                  ),
                ),
        );
      },
    );
  }

  InputDecoration _inputDec(String label, {String? prefix}) {
    return InputDecoration(
      labelText: label,
      prefixText: prefix,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
      contentPadding:
          const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
    );
  }

  Widget _card({required Widget child}) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.cardLight,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.05),
              blurRadius: 6,
              offset: const Offset(0, 2))
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: child,
    );
  }
}

// ── Transaction tile ──────────────────────────────────────────────────────────

class _TransactionTile extends StatelessWidget {
  const _TransactionTile(this.tx);
  final AgencyTransaction tx;

  Color _typeColor(String t) {
    switch (t) {
      case 'credit':
        return Colors.green;
      case 'withdrawal':
        return AppColors.primary;
      default:
        return AppColors.accent;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: ListTile(
        leading: Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: _typeColor(tx.type).withOpacity(0.12),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Icon(
            tx.type == 'credit'
                ? Icons.arrow_downward
                : tx.type == 'withdrawal'
                    ? Icons.arrow_upward
                    : Icons.swap_horiz,
            color: _typeColor(tx.type),
            size: 18,
          ),
        ),
        title: Text(tx.description,
            style:
                const TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
        subtitle: Text(tx.createdAt.toString().split(' ').first,
            style: const TextStyle(
                fontSize: 11, color: AppColors.textSecondaryLight)),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              '${tx.type == 'withdrawal' ? '-' : '+'}₹${tx.amount.toStringAsFixed(2)}',
              style: TextStyle(
                  color: _typeColor(tx.type), fontWeight: FontWeight.bold),
            ),
            Text('Bal: ₹${tx.balanceAfter.toStringAsFixed(2)}',
                style: const TextStyle(
                    fontSize: 10, color: AppColors.textSecondaryLight)),
          ],
        ),
      ),
    );
  }
}

// ── Withdrawal tile with status steps ────────────────────────────────────────

class _WithdrawalTile extends StatelessWidget {
  const _WithdrawalTile(this.w);
  final AgencyWithdrawal w;

  @override
  Widget build(BuildContext context) {
    final steps = ['requested', 'processing', 'completed'];
    final stepIdx = steps.indexOf(w.status.toLowerCase());

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text('₹${w.amount.toStringAsFixed(2)}',
                    style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: AppColors.primary)),
                const Spacer(),
                Text(w.method,
                    style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textSecondaryLight)),
              ],
            ),
            const SizedBox(height: 4),
            Text(w.accountDetails,
                style: const TextStyle(
                    fontSize: 12, color: AppColors.textSecondaryLight)),
            const SizedBox(height: 10),
            // Status steps
            Row(
              children: List.generate(steps.length * 2 - 1, (i) {
                if (i.isOdd) {
                  return Expanded(
                    child: Container(
                      height: 2,
                      color: i ~/ 2 < stepIdx
                          ? Colors.green
                          : AppColors.shimmerBase,
                    ),
                  );
                }
                final sIdx = i ~/ 2;
                final active = sIdx <= stepIdx;
                return Column(
                  children: [
                    Container(
                      width: 20,
                      height: 20,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: active ? Colors.green : AppColors.shimmerBase,
                      ),
                      child: Icon(
                        active ? Icons.check : Icons.circle_outlined,
                        size: 12,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      _stepLabel(steps[sIdx]),
                      style: TextStyle(
                          fontSize: 9,
                          color: active
                              ? Colors.green
                              : AppColors.textSecondaryLight),
                    ),
                  ],
                );
              }),
            ),
          ],
        ),
      ),
    );
  }

  String _stepLabel(String s) =>
      s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
}
