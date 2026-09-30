<div class="col-12"><fieldset class="border rounded p-3" data-address-url="{{ route('addresses.lookup') }}"><legend class="float-none w-auto fs-6 px-2">Endereço</legend><div class="row g-3">
<div class="col-md-4"><label class="form-label" for="postal_code">CEP</label><div class="input-group"><input class="form-control" id="postal_code" name="postal_code" maxlength="9" inputmode="numeric" value="{{ old('postal_code',$record->postal_code) }}"><button class="btn btn-outline-primary" type="button" data-cep-button>Buscar CEP</button></div></div>
@foreach(['street'=>'Logradouro','address_number'=>'Número','address_complement'=>'Complemento','district'=>'Bairro','city'=>'Cidade','state'=>'UF'] as $field=>$label)
<div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" maxlength="{{ $field==='state'?2:($field==='address_number'?30:160) }}" value="{{ old($field,$record->$field) }}"></div>
@endforeach
</div><p class="form-text mb-0" data-cep-status role="status" aria-live="polite">Consulta ViaCEP. Confira o endereço e informe o número e complemento.</p></fieldset></div>
<script src="{{ asset('js/address.js') }}" defer></script>
