import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type Paginado, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Fila {
    id: number;
    fecha: string;
    socio: string;
    socio_id: number;
    importe: string;
    medio: string | null;
    cuenta: string;
    concepto: string | null;
    estado: string;
    estado_label: string;
    origen: string;
    periodos: string;
    sobrante: string;
}
interface Props {
    pagos: Paginado<Fila>;
    filtros: { q: string; estado: string; desde: string; hasta: string };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pagos', href: '/pagos' }];

export default function PagosIndex({ pagos, filtros }: Props) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';
    const [q, setQ] = useState(filtros.q);
    const aplicar = (c: Partial<Props['filtros']>) => router.get(route('pagos.index'), { ...filtros, ...c }, { preserveState: true, replace: true });

    const anular = (p: Fila) => {
        const motivo = window.prompt(`Motivo de la anulación del pago de ${p.socio} (${formatMoney(p.importe)}):`);
        if (motivo) router.post(route('pagos.anular', p.id), { motivo }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pagos" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold">Pagos</h1>
                    {esTesorero && (
                        <Button asChild>
                            <Link href={route('pagos.create')}>Registrar pago</Link>
                        </Button>
                    )}
                </div>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        aplicar({ q });
                    }}
                >
                    <Input className="w-56" placeholder="Buscar socio" value={q} onChange={(e) => setQ(e.target.value)} />
                    <NativeSelect className="w-40" value={filtros.estado} onChange={(e) => aplicar({ estado: e.target.value })} aria-label="Estado">
                        <option value="">Todos los estados</option>
                        <option value="confirmado">Confirmados</option>
                        <option value="anulado">Anulados</option>
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
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>
                </form>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Fecha</TableHead>
                                <TableHead>Socio</TableHead>
                                <TableHead>Cuotas</TableHead>
                                <TableHead className="text-right">Importe</TableHead>
                                <TableHead>Medio</TableHead>
                                <TableHead>Cuenta</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {pagos.data.map((p) => (
                                <TableRow key={p.id} className={p.estado === 'anulado' ? 'text-muted-foreground line-through' : ''}>
                                    <TableCell>{formatDate(p.fecha)}</TableCell>
                                    <TableCell>
                                        <Link href={route('socios.show', p.socio_id)} className="font-medium hover:underline">
                                            {p.socio}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="max-w-[16rem] truncate capitalize">
                                        {p.periodos || '—'}
                                        {Number(p.sobrante) > 0 && (
                                            <span className="ml-1 text-xs text-green-700 normal-case no-underline">
                                                + {formatMoney(p.sobrante, { entero: true })} a favor
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">{formatMoney(p.importe)}</TableCell>
                                    <TableCell>{p.medio}</TableCell>
                                    <TableCell>{p.cuenta}</TableCell>
                                    <TableCell>
                                        <Badge variant={p.estado === 'anulado' ? 'secondary' : 'default'}>{p.estado_label}</Badge>{' '}
                                        {p.origen === 'Importación' && <span className="text-muted-foreground text-xs">importado</span>}
                                    </TableCell>
                                    <TableCell>
                                        {esTesorero && p.estado === 'confirmado' && (
                                            <Button variant="ghost" size="sm" onClick={() => anular(p)}>
                                                Anular
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {pagos.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-muted-foreground h-24 text-center">
                                        No hay pagos con esos filtros.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="text-muted-foreground flex items-center justify-between text-sm">
                    <span>
                        {pagos.total} pagos · página {pagos.current_page} de {pagos.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!pagos.prev_page_url}
                            onClick={() => pagos.prev_page_url && router.get(pagos.prev_page_url)}
                        >
                            Anterior
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!pagos.next_page_url}
                            onClick={() => pagos.next_page_url && router.get(pagos.next_page_url)}
                        >
                            Siguiente
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
