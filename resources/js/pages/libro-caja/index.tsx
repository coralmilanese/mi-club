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
    concepto: string;
    ingreso: string | null;
    egreso: string | null;
    saldo: string;
    categoria: string;
    cuenta: string;
    medio: string | null;
    es_tributo: boolean;
    anulado: boolean;
}
interface Props {
    movimientos: Paginado<Fila>;
    filtros: { cuenta_id: string; desde: string; hasta: string; categoria: string; q: string; orden: string };
    totales: { ingresos: string; egresos: string; neto: string };
    cuentas: { id: number; nombre: string; aplica_tributos: boolean; activa: boolean }[];
    medios: { id: number; nombre: string }[];
    categorias: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Libro de caja', href: '/libro-caja' }];

function NuevoMovimiento({ cuentas, medios, categorias }: Pick<Props, 'cuentas' | 'medios' | 'categorias'>) {
    const form = useForm({
        cuenta_id: String(cuentas.find((c) => c.activa)?.id ?? ''),
        tipo: 'ingreso',
        fecha: new Date().toISOString().slice(0, 10),
        concepto: '',
        categoria: '',
        importe: '',
        medio_pago_id: '',
    });
    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('libro-caja.movimientos.store'), { preserveScroll: true, onSuccess: () => form.reset('concepto', 'importe') });
    };

    return (
        <form onSubmit={enviar} className="grid gap-3 rounded-md border p-3 md:grid-cols-4">
            <div className="grid gap-1">
                <Label htmlFor="mv-cuenta">Cuenta</Label>
                <NativeSelect id="mv-cuenta" value={form.data.cuenta_id} onChange={(e) => form.setData('cuenta_id', e.target.value)}>
                    {cuentas
                        .filter((c) => c.activa)
                        .map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.nombre}
                            </option>
                        ))}
                </NativeSelect>
                <InputError message={form.errors.cuenta_id} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="mv-tipo">Tipo</Label>
                <NativeSelect id="mv-tipo" value={form.data.tipo} onChange={(e) => form.setData('tipo', e.target.value)}>
                    <option value="ingreso">Ingreso</option>
                    <option value="egreso">Egreso</option>
                </NativeSelect>
            </div>
            <div className="grid gap-1">
                <Label htmlFor="mv-fecha">Fecha</Label>
                <Input id="mv-fecha" type="date" value={form.data.fecha} onChange={(e) => form.setData('fecha', e.target.value)} />
                <InputError message={form.errors.fecha} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="mv-importe">Importe ($)</Label>
                <Input
                    id="mv-importe"
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.data.importe}
                    onChange={(e) => form.setData('importe', e.target.value)}
                />
                <InputError message={form.errors.importe} />
            </div>
            <div className="grid gap-1 md:col-span-2">
                <Label htmlFor="mv-concepto">Concepto</Label>
                <Input
                    id="mv-concepto"
                    value={form.data.concepto}
                    onChange={(e) => form.setData('concepto', e.target.value)}
                    placeholder="Ej.: IMAC Argentina"
                />
                <InputError message={form.errors.concepto} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="mv-cat">Categoría</Label>
                <Input id="mv-cat" list="categorias-libro" value={form.data.categoria} onChange={(e) => form.setData('categoria', e.target.value)} />
                <datalist id="categorias-libro">
                    {categorias.map((c) => (
                        <option key={c} value={c} />
                    ))}
                </datalist>
                <InputError message={form.errors.categoria} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="mv-medio">Medio</Label>
                <NativeSelect id="mv-medio" value={form.data.medio_pago_id} onChange={(e) => form.setData('medio_pago_id', e.target.value)}>
                    <option value="">—</option>
                    {medios.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.nombre}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <div className="md:col-span-4">
                <Button type="submit" disabled={form.processing || !form.data.concepto || !form.data.importe || !form.data.categoria}>
                    Registrar movimiento
                </Button>
                <span className="text-muted-foreground ml-3 text-xs">Los impuestos del día se calculan solos (solo en cuentas bancarias).</span>
            </div>
        </form>
    );
}

export default function LibroCajaIndex({ movimientos, filtros, totales, cuentas, medios, categorias }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const [q, setQ] = useState(filtros.q);
    const [nuevo, setNuevo] = useState(false);

    const aplicar = (cambios: Partial<Props['filtros']>) =>
        router.get(route('libro-caja.index'), { ...filtros, ...cambios }, { preserveState: true, replace: true });
    const exportar = route('libro-caja.exportar', Object.fromEntries(Object.entries(filtros).filter(([k, v]) => v !== '' && k !== 'orden')));

    const anular = (m: Fila) => {
        const motivo = window.prompt(`Motivo de la anulación de "${m.concepto}":`);
        if (motivo) router.post(route('libro-caja.movimientos.anular', m.id), { motivo }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Libro de caja" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold">Libro de caja</h1>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <a href={exportar}>Exportar a Excel</a>
                        </Button>
                        {esTesorero && <Button onClick={() => setNuevo((v) => !v)}>{nuevo ? 'Cerrar' : 'Nuevo movimiento'}</Button>}
                    </div>
                </div>

                {esTesorero && nuevo && <NuevoMovimiento cuentas={cuentas} medios={medios} categorias={categorias} />}

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        aplicar({ q });
                    }}
                >
                    <NativeSelect
                        className="w-44"
                        value={filtros.cuenta_id}
                        onChange={(e) => aplicar({ cuenta_id: e.target.value })}
                        aria-label="Cuenta"
                    >
                        <option value="">Todas las cuentas</option>
                        {cuentas.map((c) => (
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
                    <NativeSelect
                        className="w-52"
                        value={filtros.categoria}
                        onChange={(e) => aplicar({ categoria: e.target.value })}
                        aria-label="Categoría"
                    >
                        <option value="">Todas las categorías</option>
                        {categorias.map((c) => (
                            <option key={c} value={c}>
                                {c}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input className="w-52" placeholder="Buscar en el concepto" value={q} onChange={(e) => setQ(e.target.value)} />
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => aplicar({ orden: filtros.orden === 'desc' ? 'asc' : 'desc' })}>
                        {filtros.orden === 'desc' ? 'Más nuevos primero ↓' : 'Más viejos primero ↑'}
                    </Button>
                </form>

                <div className="grid gap-3 md:grid-cols-3">
                    {[
                        ['Ingresos', totales.ingresos],
                        ['Egresos', totales.egresos],
                        ['Neto del período', totales.neto],
                    ].map(([titulo, valor]) => (
                        <Card key={titulo}>
                            <CardContent className="pt-4">
                                <p className="text-muted-foreground text-xs">{titulo}</p>
                                <p className="text-xl font-semibold">{formatMoney(valor)}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Fecha</TableHead>
                                <TableHead>Concepto</TableHead>
                                <TableHead>Cuenta</TableHead>
                                <TableHead className="text-right">Ingreso</TableHead>
                                <TableHead className="text-right">Egreso</TableHead>
                                <TableHead className="text-right">Saldo</TableHead>
                                <TableHead>Categoría</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movimientos.data.map((m) => (
                                <TableRow
                                    key={m.id}
                                    className={m.anulado ? 'text-muted-foreground line-through' : m.es_tributo ? 'text-muted-foreground' : ''}
                                >
                                    <TableCell>{formatDate(m.fecha)}</TableCell>
                                    <TableCell className="max-w-[26rem] truncate">{m.concepto}</TableCell>
                                    <TableCell>{m.cuenta}</TableCell>
                                    <TableCell className="text-right">{m.ingreso ? formatMoney(m.ingreso) : ''}</TableCell>
                                    <TableCell className="text-right">{m.egreso ? formatMoney(m.egreso) : ''}</TableCell>
                                    <TableCell className="text-right font-medium">{formatMoney(m.saldo)}</TableCell>
                                    <TableCell>
                                        {m.categoria}
                                        {m.es_tributo && (
                                            <Badge variant="outline" className="ml-2">
                                                auto
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {esTesorero && !m.es_tributo && !m.anulado && (
                                            <Button variant="ghost" size="sm" onClick={() => anular(m)}>
                                                Anular
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {movimientos.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-muted-foreground h-24 text-center">
                                        No hay movimientos con esos filtros.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="text-muted-foreground flex items-center justify-between text-sm">
                    <span>
                        {movimientos.total} movimientos · página {movimientos.current_page} de {movimientos.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!movimientos.prev_page_url}
                            onClick={() => movimientos.prev_page_url && router.get(movimientos.prev_page_url)}
                        >
                            Anterior
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!movimientos.next_page_url}
                            onClick={() => movimientos.next_page_url && router.get(movimientos.next_page_url)}
                        >
                            Siguiente
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
