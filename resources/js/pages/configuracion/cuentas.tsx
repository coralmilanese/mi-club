import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type Opcion, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface CuentaItem {
    id: number;
    nombre: string;
    tipo: string;
    tipo_label: string;
    titular: string | null;
    aplica_tributos: boolean;
    activa: boolean;
    saldo: string;
    con_apertura: boolean;
}
interface Props {
    cuentas: CuentaItem[];
    tipos: Opcion[];
    medios: { id: number; nombre: string; activo: boolean; cuenta_default_id: number | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cuentas y medios de pago', href: '/configuracion/cuentas' }];

function Apertura({ cuenta }: { cuenta: CuentaItem }) {
    const form = useForm({ saldo: '', fecha: new Date().getFullYear() + '-01-01' });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('cuentas.apertura', cuenta.id), { preserveScroll: true, onSuccess: () => form.reset('saldo') });
    };
    const errores = form.errors as Record<string, string>;

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-start gap-2 border-t pt-3">
            <div className="grid gap-1">
                <Input
                    type="number"
                    step="0.01"
                    min="0"
                    className="w-40"
                    placeholder="Saldo inicial"
                    value={form.data.saldo}
                    onChange={(e) => form.setData('saldo', e.target.value)}
                    aria-label="Saldo inicial"
                />
                <InputError message={errores.saldo} />
            </div>
            <Input
                type="date"
                className="w-40"
                value={form.data.fecha}
                onChange={(e) => form.setData('fecha', e.target.value)}
                aria-label="Fecha de apertura"
            />
            <Button type="submit" size="sm" variant="secondary" disabled={form.processing || form.data.saldo === ''}>
                Registrar apertura
            </Button>
        </form>
    );
}

function NuevaCuenta({ tipos }: { tipos: Opcion[] }) {
    const form = useForm<{ nombre: string; tipo: string; titular: string; aplica_tributos: boolean }>({
        nombre: '',
        tipo: 'efectivo',
        titular: '',
        aplica_tributos: false,
    });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('cuentas.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={enviar} className="flex flex-wrap items-start gap-3">
            <div className="grid gap-1">
                <Label htmlFor="c-nombre">Nombre</Label>
                <Input id="c-nombre" value={form.data.nombre} onChange={(e) => form.setData('nombre', e.target.value)} />
                <InputError message={form.errors.nombre} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="c-tipo">Tipo</Label>
                <NativeSelect id="c-tipo" className="w-40" value={form.data.tipo} onChange={(e) => form.setData('tipo', e.target.value)}>
                    {tipos.map((t) => (
                        <option key={t.value} value={t.value}>
                            {t.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <Label className="mt-6 flex items-center gap-2">
                <Checkbox checked={form.data.aplica_tributos} onCheckedChange={(v) => form.setData('aplica_tributos', v === true)} />
                Liquida impuestos
            </Label>
            <Button type="submit" className="mt-5" disabled={form.processing || !form.data.nombre}>
                Crear cuenta
            </Button>
        </form>
    );
}

export default function Cuentas({ cuentas, tipos, medios }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const [abrir, setAbrir] = useState<number | null>(null);

    const actualizar = (c: CuentaItem, cambios: Partial<Pick<CuentaItem, 'aplica_tributos' | 'activa'>>) =>
        router.put(
            route('cuentas.update', c.id),
            { nombre: c.nombre, titular: c.titular, aplica_tributos: c.aplica_tributos, activa: c.activa, ...cambios },
            { preserveScroll: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cuentas y medios de pago" />
            <div className="flex max-w-5xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Cuentas y medios de pago</h1>
                {esTesorero && <NuevaCuenta tipos={tipos} />}

                <div className="grid gap-4 md:grid-cols-2">
                    {cuentas.map((c) => (
                        <Card key={c.id} className={c.activa ? '' : 'opacity-60'}>
                            <CardHeader>
                                <CardTitle className="flex flex-wrap items-center gap-2">
                                    {c.nombre}
                                    <Badge variant="outline">{c.tipo_label}</Badge>
                                    {c.aplica_tributos && <Badge variant="secondary">Liquida impuestos</Badge>}
                                    {!c.activa && <Badge variant="secondary">Inactiva</Badge>}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                <p>
                                    <span className="text-muted-foreground">Saldo calculado: </span>
                                    <span className="text-lg font-semibold">{formatMoney(c.saldo)}</span>
                                </p>
                                {esTesorero && (
                                    <div className="flex flex-wrap gap-4">
                                        <Label className="flex items-center gap-2">
                                            <Checkbox
                                                checked={c.aplica_tributos}
                                                onCheckedChange={(v) => actualizar(c, { aplica_tributos: v === true })}
                                            />
                                            Liquida impuestos
                                        </Label>
                                        <Label className="flex items-center gap-2">
                                            <Checkbox checked={c.activa} onCheckedChange={(v) => actualizar(c, { activa: v === true })} />
                                            Activa
                                        </Label>
                                    </div>
                                )}
                                {esTesorero && !c.con_apertura && (
                                    <>
                                        <Button variant="ghost" size="sm" className="w-fit" onClick={() => setAbrir(abrir === c.id ? null : c.id)}>
                                            Cargar saldo de apertura
                                        </Button>
                                        {abrir === c.id && <Apertura cuenta={c} />}
                                    </>
                                )}
                                {c.con_apertura && <p className="text-muted-foreground text-xs">Apertura registrada.</p>}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Medios de pago</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
                        {medios.map((m) => (
                            <div key={m.id} className="flex flex-wrap items-center gap-3">
                                <span className="w-56 font-medium">{m.nombre}</span>
                                <NativeSelect
                                    className="w-52"
                                    disabled={!esTesorero}
                                    value={m.cuenta_default_id ?? ''}
                                    onChange={(e) =>
                                        router.put(
                                            route('medios-pago.update', m.id),
                                            { cuenta_default_id: e.target.value || null, activo: m.activo },
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label={`Cuenta por defecto de ${m.nombre}`}
                                >
                                    <option value="">Sin cuenta por defecto</option>
                                    {cuentas.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.nombre}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                        ))}
                        <p className="text-muted-foreground text-xs">Los pagos en efectivo van a la cuenta Efectivo.</p>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
