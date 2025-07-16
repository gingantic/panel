import React, { useState } from 'react';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { useTransactions, TransactionFilters } from '@/api/account/transactions';
import Spinner from '@/components/elements/Spinner';
import FlashMessageRender from '@/components/FlashMessageRender';
import { useFlashKey } from '@/plugins/useFlash';
import PaginationFooter from '@/components/elements/table/PaginationFooter';
import classNames from 'classnames';

export default () => {
    const { clearAndAddHttpError } = useFlashKey('account');
    const [filters, setFilters] = useState<TransactionFilters>({ page: 1, sorts: { created_at: -1 } });

    const { data, error, isValidating } = useTransactions(filters, {
        revalidateOnMount: true,
        revalidateOnFocus: false,
    });

    if (error) {
        clearAndAddHttpError(error);
    }

    return (
        <PageContentBlock title={'Credit History'}>
            <FlashMessageRender byKey={'account'} />

            {!data && isValidating ? (
                <Spinner centered />
            ) : (
                <div className={'overflow-x-auto'}>
                    <table className={'w-full text-sm'}>
                        <thead>
                            <tr className={'text-left bg-neutral-700'}>
                                <th className={'p-2'}>Date</th>
                                <th className={'p-2'}>Description</th>
                                <th className={'p-2'}>Type</th>
                                <th className={'p-2 text-right'}>Amount</th>
                                <th className={'p-2 text-right'}>Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data?.items.map((tx, index) => (
                                <tr
                                    key={tx.id}
                                    className={classNames(index % 2 === 0 ? 'bg-neutral-800' : 'bg-neutral-700')}
                                >
                                    <td className={'p-2'}>
                                        {tx.createdAt.toLocaleString(undefined, {
                                            year: 'numeric',
                                            month: 'short',
                                            day: 'numeric',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </td>
                                    <td className={'p-2'}>{tx.description || '-'}</td>
                                    <td className={'p-2 capitalize'}>{tx.type}</td>
                                    <td className={'p-2 text-right'}>
                                        <span className={tx.type === 'credit' ? 'text-green-400' : 'text-red-400'}>
                                            {tx.type === 'credit' ? '+' : '-'}
                                            {tx.amount}
                                        </span>
                                    </td>
                                    <td className={'p-2 text-right'}>
                                        {tx.balanceAfter !== null ? tx.balanceAfter : '-'}
                                    </td>
                                </tr>
                            ))}
                            {data && data.items.length === 0 && (
                                <tr>
                                    <td colSpan={5} className={'p-4 text-center'}>
                                        No transactions found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {data && (
                <PaginationFooter
                    pagination={data.pagination}
                    onPageSelect={(page) => setFilters((s) => ({ ...s, page }))}
                />
            )}
        </PageContentBlock>
    );
}; 