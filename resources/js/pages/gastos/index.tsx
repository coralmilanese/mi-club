import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type Paginado, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Fila {
    id: number;
    fecha: string;
    categoria: string;
    descripcion: string;
    proveedor: string | null;
    importe: string;
    cuenta: string;
    medio: string | null;
    anulado: boolean;
}
interface Props {
    gastos: Paginado<Fila>;
    total: string;
    filtros: { categoria_gasto_id: string; desde: string; hasta: string };
    categorias: { id: number; nombre: string; activa: boolean }[];
    medios: { id: number; nombre: string; cuenta_default_id: number | null }[];
    cuentas: { id: number; nombre: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Gastos', href: '/gastos' }];

function NuevoGasto({ categorias, medios, cuentas }: Pick<Props, 'categorias' | 'medios' | 'cuentas'>) {
    const form = useForm<{
        categoria_gasto_id: string;
        fecha: string;
        importe: string;
        descripcion: string;
        proveedor: string;
        medio_pago_id: string;
        cuenta_id: string;
        comprobante: File | null;
    }>({
        categoria_gasto_id: '',
        fecha: new Date().toISOString().slice(0, 10),
        importe: '',
        descripcion: '',
        proveedor: '',
        medio_pago_id: String(medios[0]?.id ?? ''),
        cuenta_id: String(medios[0]?.cuenta_default_id ?? cuentas[0]?.id ?? ''),
        comprobante: null,
    });
    const errores = form.errors as Record<string, string>;
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('gastos.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('importe', 'descripcion', 'proveedor', 'comprobante'),
        });
    };

    return (
        <form onSubmit={enviar} className="grid gap-3 rounded-md border p-3 md:grid-cols-4">
            <div className="grid gap-1">
                <Label htmlFor="g-cat">Categoría</Label>
                <NativeSelect id="g-cat" value={form.data.categoria_gasto_id} onChange={(e) => form.setData('categoria_gasto_id', e.target.value)}>
                    <option value="">Elegí…</option>
                    {categorias
                        .filter((c) => c.activa)
                        .map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.nombre}
                            </option>
                        ))}
                </NativeSelect>
                <InputError message={errores.categoria_gasto_id} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="g-fecha">Fecha</Label>
                <Input id="g-fecha" type="date" value={form.data.fecha} onChange={(e) => form.setData('fecha', e.target.value)} />
                <InputError message={errores.fecha} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="g-importe">Importe ($)</Label>
                <Input
                    id="g-importe"
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.data.importe}
                    onChange={(e) => form.setData('importe', e.target.value)}
                />
                <InputError message={errores.importe} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="g-cuenta">Cuenta</Label>
                <NativeSelect id="g-cuenta" value={form.data.cuenta_id} onChange={(e) => form.setData('cuenta_id', e.target.value)}>
                    {cuentas.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.nombre}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <div className="grid gap-1 md:col-span-2">
                <Label htmlFor="g-desc">Descripción</Label>
                <Input
                    id="g-desc"
                    value={form.data.descripcion}
                    onChange={(e) => form.setData('descripcion', e.target.value)}
                    placeholder="Ej.: Planeadores luz y socios octubre"
                />
                <InputError message={errores.descripcion} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="g-prov">Proveedor</Label>
                <Input id="g-prov" value={form.data.proveedor} onChange={(e) => form.setData('proveedor', e.target.value)} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="g-medio">Medio</Label>
                <NativeSelect id="g-medio" value={form.data.medio_pago_id} onChange={(e) => form.setData('medio_pago_id', e.target.value)}>
                    {medios.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.nombre}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <div className="grid gap-1 md:col-span-2">
                <Label htmlFor="g-comp">Comprobante (opcional)</Label>
                <Input
                    id="g-comp"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    onChange={(e) => form.setData('comprobante', e.target.files?.[0] ?? null)}
                />
                <InputError message={errores.comprobante} />
            </div>
            <div className="flex items-end">
                <Button type="submit" disabled={form.processing || !form.data.categoria_gasto_id || !form.data.importe || !form.data.descripcion}>
                    Registrar gasto
                </Button>
            </div>
        </form>
    );
}

export default function GastosIndex({ gastos, total, filtros, categorias, medios, cuentas }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const [nuevo, setNuevo] = useState(false);
    const aplicar = (c: Partial<Props['filtros']>) => router.get(route('gastos.index'), { ...filtros, ...c }, { preserveState: true, replace: true });

    const anular = (g: Fila) => {
        const motivo = window.prompt(`Motivo de la anulación de "${g.descripcion}":`);
        if (motivo) router.post(route('gastos.anular', g.id), { motivo }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Gastos" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold">Gastos</h1>
                    {esTesorero && <Button onClick={() => setNuevo((v) => !v)}>{nuevo ? 'Cerrar' : 'Registrar gasto'}</Button>}
                </div>
                {esTesorero && nuevo && <NuevoGasto categorias={categorias} medios={medios} cuentas={cuentas} />}

                <div className="flex flex-wrap items-end gap-2">
                    <NativeSelect
                        className="w-56"
                        value={filtros.categoria_gasto_id}
                        onChange={(e) => aplicar({ categoria_gasto_id: e.target.value })}
                        aria-label="Categoría"
                    >
                        <option value="">Todas las categorías</option>
                        {categorias.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.nombre}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input
                        type="date"
                        className="w-40"
                        value={filtros.desde}
                        onChange={(e) => aplicar({ desde: e.target.value })}
                        aria-label="Desde"
                    />
                    <Input
                        type="date"
                        className="w-40"
                        value={filtros.hasta}
                        onChange={(e) => aplicar({ hasta: e.target.value })}
                        aria-label="Hasta"
                    />
                    <Card className="ml-auto">
                        <CardContent className="px-4 py-2">
                            <span className="text-muted-foreground text-xs">Total (sin anulados) </span>
                            <span className="text-lg font-semibold">{formatMoney(total)}</span>
                        </CardContent>
                    </Card>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Fecha</TableHead>
                                <TableHead>Categoría</TableHead>
                                <TableHead>Descripción</TableHead>
                                <TableHead className="text-right">Importe</TableHead>
                                <TableHead>Cuenta</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {gastos.data.map((g) => (
                                <TableRow key={g.id} className={g.anulado ? 'text-muted-foreground line-through' : ''}>
                                    <TableCell>{formatDate(g.fecha)}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline">{g.categoria}</Badge>
                                    </TableCell>
                                    <TableCell className="max-w-[28rem] truncate">
                                        {g.descripcion}
                                        {g.proveedor && <span className="text-muted-foreground"> · {g.proveedor}</span>}
                                    </TableCell>
                                    <TableCell className="text-right">{formatMoney(g.importe)}</TableCell>
                                    <TableCell>{g.cuenta}</TableCell>
                                    <TableCell>
                                        {esTesorero && !g.anulado && (
                                            <Button variant="ghost" size="sm" onClick={() => anular(g)}>
                                                Anular
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {gastos.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-muted-foreground h-24 text-center">
                                        No hay gastos con esos filtros.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
                <p className="text-muted-foreground text-sm">
                    {gastos.total} gastos · página {gastos.current_page} de {gastos.last_page}
                </p>
            </div>
        </AppLayout>
    );
}
