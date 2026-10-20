{{--
  The Alpine components of the cashier dialogs. Rendered once with the page (render hook BODY_END, see
  Cashier::registerRenderHooks()), so a Livewire request of the page never sends them again; they run before Livewire
  starts Alpine (on DOMContentLoaded). The markup that uses them is in payment/modal, payment/success-modal and
  bills/preview-modal.
--}}
@include('filament.tenant.pages.cashier.payment.script')
@include('filament.tenant.pages.cashier.payment.success-script')
@include('filament.tenant.pages.cashier.bills.preview-script')
