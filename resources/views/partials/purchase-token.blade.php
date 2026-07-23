{{-- Signed, opaque purchase-idempotency token. Bound server-side to the user +
     purchase target; carries no price/discount authority. Only for authed users. --}}
@auth
    <input type="hidden" name="purchase_token"
           value="{{ \App\Support\PurchaseToken::issue(auth()->id(), $op, $planId ?? null, $serviceId ?? null, $options ?? []) }}">
@endauth
