import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface Fila {
    id: number;
    tipo: string;
    estado: string;
    estado_label: string;
    chat: string;
    texto_original: string | null;
    socio_sugerido: string | null;
    importe: string | null;
    confianza: string | null;
    error_mensaje: string | null;
    creado: string;
    comprobante_url: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bandeja de Telegram', href: '/telegram/pendientes' }];

const COLOR: Record<string, string> = {
    esperando_confirmacion: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    esperando_socio: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    esperando_fecha: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    error: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
    expirada: 'bg-muted text-muted-foreground',
};

export default function Pendientes({ ingestas }: { ingestas: Fila[] }) {
    const { auth, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const esTesorero = auth.user.rol === 'tesorero';

    const confirmar = (i: Fila) => {
        if (confirm(`¿Confirmar el pago de ${i.socio_sugerido ?? '¿?'} por ${formatMoney(i.importe ?? 0)}?`)) {
            router.post(route('telegram.pendientes.confirmar', i.id), {}, { preserveScroll: true });
        }
    };
    const descartar = (i: Fila) => {
        const motivo = window.prompt('Motivo (opcional):') ?? '';
        router.post(route('telegram.pendientes.descartar', i.id), { motivo }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bandeja de Telegram" />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Bandeja de Telegram</h1>
                <p className="text-muted-foreground max-w-2xl text-sm">
                    Comprobantes y avisos de cobro que el bot no pudo terminar de resolver solo: socio ambiguo, fecha ilegible, un error, o quedaron
                    sin confirmar más de una semana.
                </p>
                <InputError message={errors.ingesta} />

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Llegó</TableHead>
                                <TableHead>De</TableHead>
                                <TableHead>Tipo</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead>Socio sugerido</TableHead>
                                <TableHead className="text-right">Importe</TableHead>
                                <TableHead>Detalle</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ingestas.map((i) => (
                                <TableRow key={i.id}>
                                    <TableCell className="text-muted-foreground text-xs">{i.creado}</TableCell>
                                    <TableCell>{i.chat}</TableCell>
                                    <TableCell>
                                        {i.tipo}
                                        {i.comprobante_url && (
                                            <>
                                                {' · '}
                                                <a className="underline" href={i.comprobante_url} target="_blank" rel="noreferrer">
                                                    ver
                                                </a>
                                            </>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <span className={`rounded px-2 py-0.5 text-xs ${COLOR[i.estado] ?? ''}`}>{i.estado_label}</span>
                                    </TableCell>
                                    <TableCell>{i.socio_sugerido ?? '—'}</TableCell>
                                    <TableCell className="text-right">{i.importe ? formatMoney(i.importe) : '—'}</TableCell>
                                    <TableCell className="text-muted-foreground max-w-[20rem] truncate text-xs">
                                        {i.error_mensaje ?? i.texto_original ?? ''}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {esTesorero && (
                                            <div className="flex justify-end gap-1">
                                                {i.estado === 'esperando_confirmacion' && i.socio_sugerido && (
                                                    <Button size="sm" onClick={() => confirmar(i)}>
                                                        Confirmar
                                                    </Button>
                                                )}
                                                <Button size="sm" variant="ghost" onClick={() => descartar(i)}>
                                                    Descartar
                                                </Button>
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {ingestas.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-muted-foreground h-24 text-center">
                                        No hay nada pendiente. 👍
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
